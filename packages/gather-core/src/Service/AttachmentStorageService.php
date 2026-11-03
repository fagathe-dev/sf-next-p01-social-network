<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Fagathe\GatherCore\Dto\Output\AttachmentOutput;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\PostAttachment;
use Fagathe\GatherCore\Enum\Social\AttachmentType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Stockage des fichiers attachés, selon la double politique de la section 2.5 :
 *  - post public : public/uploads/posts, servi directement (cache CDN, crawlers) ;
 *  - post privé : var/private_media, servi uniquement par le contrôleur sécurisé (HTTP 206).
 */
final class AttachmentStorageService
{
    private const MEGABYTE = 1048576;
    private const MAX_IMAGE_PIXELS = 40000000;
    private const MAX_IMAGE_SIDE = 2400;
    private const THUMBNAIL_WIDTH = 640;
    private const WEBP_QUALITY = 82;

    /** Taille maximale par type, en Mo */
    private const MAX_SIZE_MB = [
        AttachmentType::Image->value => 15,
        AttachmentType::Video->value => 200,
        AttachmentType::Audio->value => 30,
        AttachmentType::Document->value => 25,
    ];

    /** Type MIME détecté sur le contenu réel du fichier (jamais celui annoncé par le client) */
    private const ALLOWED_MIME = [
        'image/jpeg' => AttachmentType::Image,
        'image/png' => AttachmentType::Image,
        'image/webp' => AttachmentType::Image,
        'video/mp4' => AttachmentType::Video,
        'video/webm' => AttachmentType::Video,
        'audio/mpeg' => AttachmentType::Audio,
        'audio/mp3' => AttachmentType::Audio,
        'audio/aac' => AttachmentType::Audio,
        'audio/x-hx-aac-adts' => AttachmentType::Audio,
        'audio/ogg' => AttachmentType::Audio,
        'audio/webm' => AttachmentType::Audio,
        'audio/mp4' => AttachmentType::Audio,
        'application/pdf' => AttachmentType::Document,
        'application/zip' => AttachmentType::Document,
    ];

    private const SUPPORT_MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    private const SUPPORT_MAX_SIZE_MB = 10;

    private const EXTENSIONS = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/aac' => 'aac',
        'audio/x-hx-aac-adts' => 'aac',
        'audio/ogg' => 'ogg',
        'audio/webm' => 'webm',
        'audio/mp4' => 'm4a',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
    ];

    private readonly Filesystem $filesystem;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly string $defaultUri,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * @throws AttachmentRejectedException si le fichier n'est pas autorisé
     */
    public function store(UploadedFile $file, Post $post, int $position): PostAttachment
    {
        $mime = $this->detectMime($file);
        $type = $this->assertAllowed($file, $mime);
        $root = $post->hasPublicMedia() ? $this->publicRoot() : $this->privateRoot();
        $directory = date('Y/m');
        $name = (string) Uuid::v7();

        if ($type === AttachmentType::Image) {
            [$filePath, $thumbnailPath, $metadata, $size] = $this->storeImage($file, $root, $directory, $name);

            return new PostAttachment($post, $type, $filePath, (string) $file->getClientOriginalName(), 'image/webp', $size, $position, $thumbnailPath, $metadata);
        }

        $filePath = sprintf('%s/%s.%s', $directory, $name, self::EXTENSIONS[$mime]);
        $size = (int) $file->getSize();
        $this->filesystem->mkdir($root . '/' . $directory);
        $file->move($root . '/' . $directory, basename($filePath));

        return new PostAttachment($post, $type, $filePath, (string) $file->getClientOriginalName(), $mime, $size, $position);
    }

    /** Image d'avatar ou de bannière : toujours publique, convertie en WebP. */
    public function storePublicImage(UploadedFile $file, string $subDirectory): string
    {
        $mime = $this->detectMime($file);
        if ($this->assertAllowed($file, $mime) !== AttachmentType::Image) {
            throw new AttachmentRejectedException('Seules les images sont acceptées.');
        }

        $root = $this->projectDir . '/public/uploads/' . $subDirectory;
        [$filePath] = $this->storeImage($file, $root, date('Y/m'), (string) Uuid::v7(), withThumbnail: false);

        return sprintf('%s/uploads/%s/%s', rtrim($this->defaultUri, '/'), $subDirectory, $filePath);
    }

    /** Pièce jointe d'un message : jamais publique. Retourne le chemin relatif à la racine privée. */
    public function storePrivateFile(UploadedFile $file, string $subDirectory): string
    {
        $mime = $this->detectMime($file);
        $type = $this->assertAllowed($file, $mime);
        $relative = $subDirectory . '/' . date('Y/m');
        $name = (string) Uuid::v7();

        if ($type === AttachmentType::Image) {
            [$filePath] = $this->storeImage($file, $this->privateRoot(), $relative, $name, withThumbnail: false);

            return $filePath;
        }

        $this->filesystem->mkdir($this->privateRoot() . '/' . $relative);
        $file->move($this->privateRoot() . '/' . $relative, $name . '.' . self::EXTENSIONS[$mime]);

        return sprintf('%s/%s.%s', $relative, $name, self::EXTENSIONS[$mime]);
    }

    /**
     * Pièce jointe d'un ticket : capture d'écran ou PDF, jamais publique.
     *
     * @return array{path: string, name: string, mimeType: string, size: int}
     *
     * @throws AttachmentRejectedException si le fichier n'est pas autorisé
     */
    public function storeSupportFile(UploadedFile $file): array
    {
        $name = (string) $file->getClientOriginalName();
        if (!in_array($this->detectMime($file), self::SUPPORT_MIME, true)) {
            throw new AttachmentRejectedException(sprintf('Le fichier « %s » doit être une image (JPEG, PNG, WebP) ou un PDF.', $name));
        }
        if ((int) $file->getSize() > self::SUPPORT_MAX_SIZE_MB * self::MEGABYTE) {
            throw new AttachmentRejectedException(sprintf('Le fichier « %s » dépasse la limite de %d Mo.', $name, self::SUPPORT_MAX_SIZE_MB));
        }

        return $this->storeDescribedFile($file, 'support');
    }

    /**
     * @return array{path: string, name: string, mimeType: string, size: int}
     *
     * @throws AttachmentRejectedException si le fichier n'est pas autorisé
     */
    public function storeMessageFile(UploadedFile $file): array
    {
        return $this->storeDescribedFile($file, 'messages');
    }

    /**
     * @return array{path: string, name: string, mimeType: string, size: int}
     */
    private function storeDescribedFile(UploadedFile $file, string $subDirectory): array
    {
        $name = (string) $file->getClientOriginalName();
        $mime = $this->detectMime($file);
        $path = $this->storePrivateFile($file, $subDirectory);

        return [
            'path' => $path,
            'name' => $name,
            // Les images sont converties en WebP à l'enregistrement
            'mimeType' => str_starts_with($mime, 'image/') ? 'image/webp' : $mime,
            'size' => (int) filesize($this->privateAbsolutePath($path)),
        ];
    }

    public function output(PostAttachment $attachment): AttachmentOutput
    {
        $isPublic = $attachment->getPost()->hasPublicMedia();
        $base = rtrim($this->defaultUri, '/');
        $id = (string) $attachment->getId();

        // Média privé : URL relative, servie via le front, seule origine qui détient le cookie d'authentification
        $url = $isPublic ? sprintf('%s/uploads/posts/%s', $base, $attachment->getFilePath()) : sprintf('/api/media/%s', $id);
        $thumbnail = null;
        if ($attachment->getThumbnailPath() !== null) {
            $thumbnail = $isPublic
                ? sprintf('%s/uploads/posts/%s', $base, $attachment->getThumbnailPath())
                : sprintf('/api/media/%s?thumbnail=1', $id);
        }

        return new AttachmentOutput(
            $id,
            $attachment->getType()->value,
            $url,
            $thumbnail,
            $attachment->getOriginalName(),
            $attachment->getMimeType(),
            $attachment->getFileSize(),
            $isPublic,
            $attachment->getMetadata(),
        );
    }

    public function absolutePath(PostAttachment $attachment, bool $thumbnail = false): string
    {
        $root = $attachment->getPost()->hasPublicMedia() ? $this->publicRoot() : $this->privateRoot();
        $relative = $thumbnail && $attachment->getThumbnailPath() !== null ? $attachment->getThumbnailPath() : $attachment->getFilePath();

        return $root . '/' . $relative;
    }

    /**
     * @return list<string>
     */
    public function attachmentFiles(PostAttachment $attachment): array
    {
        $files = [$this->absolutePath($attachment)];
        if ($attachment->getThumbnailPath() !== null) {
            $files[] = $this->absolutePath($attachment, thumbnail: true);
        }

        return $files;
    }

    /**
     * Chemin d'un avatar ou d'une bannière hébergé ici ; null pour une URL externe ou hors de ces dossiers.
     */
    public function publicImageFile(?string $url): ?string
    {
        $parts = parse_url((string) $url);
        $path = (string) ($parts['path'] ?? '');

        if (($parts['host'] ?? null) !== parse_url($this->defaultUri, PHP_URL_HOST)
            || preg_match('#^/uploads/(avatars|banners)/#', $path) !== 1
            || str_contains($path, '..')
            || str_contains($path, '\\')) {
            return null;
        }

        return $this->projectDir . '/public' . $path;
    }

    /**
     * Un fichier resté sur le disque ne doit pas faire échouer l'action qui vient d'aboutir en base.
     *
     * @param list<string> $files
     */
    public function deleteFiles(array $files): void
    {
        foreach ($files as $file) {
            try {
                $this->filesystem->remove($file);
            } catch (IOExceptionInterface) {
                continue;
            }
        }
    }

    /** L'audience d'une publication a changé : ses fichiers passent de la racine publique à la privée, ou l'inverse. */
    public function relocate(PostAttachment $attachment, bool $fromPublic): void
    {
        $from = $fromPublic ? $this->publicRoot() : $this->privateRoot();
        $to = $fromPublic ? $this->privateRoot() : $this->publicRoot();

        foreach (array_filter([$attachment->getFilePath(), $attachment->getThumbnailPath()]) as $relative) {
            if (is_file($from . '/' . $relative)) {
                $this->filesystem->mkdir(dirname($to . '/' . $relative));
                $this->filesystem->rename($from . '/' . $relative, $to . '/' . $relative, true);
            }
        }
    }

    public function privateAbsolutePath(string $relative): string
    {
        return $this->privateRoot() . '/' . $relative;
    }

    private function detectMime(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();

        // Un enregistrement vocal WebM est identifié « video/webm » par son conteneur
        if ($mime === 'video/webm' && str_starts_with((string) $file->getClientMimeType(), 'audio/')) {
            return 'audio/webm';
        }
        if ($mime === 'video/mp4' && str_starts_with((string) $file->getClientMimeType(), 'audio/')) {
            return 'audio/mp4';
        }

        return $mime;
    }

    private function assertAllowed(UploadedFile $file, string $mime): AttachmentType
    {
        if (!$file->isValid()) {
            throw new AttachmentRejectedException(sprintf('Le fichier « %s » n\'a pas pu être reçu.', $file->getClientOriginalName()));
        }

        $type = self::ALLOWED_MIME[$mime] ?? null;
        if ($type === null) {
            throw new AttachmentRejectedException(sprintf('Le format du fichier « %s » n\'est pas accepté.', $file->getClientOriginalName()));
        }

        $maxMb = self::MAX_SIZE_MB[$type->value];
        if ((int) $file->getSize() > $maxMb * self::MEGABYTE) {
            throw new AttachmentRejectedException(sprintf('Le fichier « %s » dépasse la limite de %d Mo.', $file->getClientOriginalName(), $maxMb));
        }

        return $type;
    }

    /**
     * @return array{0: string, 1: ?string, 2: array{width: int, height: int}, 3: int}
     */
    private function storeImage(UploadedFile $file, string $root, string $directory, string $name, bool $withThumbnail = true): array
    {
        $path = $file->getPathname();
        $info = @getimagesize($path);
        if ($info === false || $info[0] * $info[1] > self::MAX_IMAGE_PIXELS) {
            throw new AttachmentRejectedException(sprintf('L\'image « %s » est invalide ou trop grande.', $file->getClientOriginalName()));
        }

        $source = @imagecreatefromstring((string) file_get_contents($path));
        if ($source === false) {
            throw new AttachmentRejectedException(sprintf('L\'image « %s » est illisible.', $file->getClientOriginalName()));
        }

        $source = $this->applyExifOrientation($source, $path, $info[2]);
        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $this->filesystem->mkdir($root . '/' . $directory);

        $image = $this->resizeTo($source, self::MAX_IMAGE_SIDE);
        $filePath = sprintf('%s/%s.webp', $directory, $name);
        imagewebp($image, $root . '/' . $filePath, self::WEBP_QUALITY);
        $metadata = ['width' => imagesx($image), 'height' => imagesy($image)];

        $thumbnailPath = null;
        if ($withThumbnail) {
            $thumbnailPath = sprintf('%s/%s-thumb.webp', $directory, $name);
            imagewebp($this->resizeTo($source, self::THUMBNAIL_WIDTH), $root . '/' . $thumbnailPath, self::WEBP_QUALITY);
        }

        return [$filePath, $thumbnailPath, $metadata, (int) filesize($root . '/' . $filePath)];
    }

    private function resizeTo(\GdImage $image, int $maxSide): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) <= $maxSide) {
            return $image;
        }

        $ratio = $maxSide / max($width, $height);
        $resized = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));

        return $resized !== false ? $resized : $image;
    }

    private function applyExifOrientation(\GdImage $image, string $path, int $imageType): \GdImage
    {
        if ($imageType !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (@exif_read_data($path) ?: [])['Orientation'] ?? 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle !== 0 ? (imagerotate($image, $angle, 0) ?: $image) : $image;
    }

    private function publicRoot(): string
    {
        return $this->projectDir . '/public/uploads/posts';
    }

    private function privateRoot(): string
    {
        return $this->projectDir . '/var/private_media';
    }
}
