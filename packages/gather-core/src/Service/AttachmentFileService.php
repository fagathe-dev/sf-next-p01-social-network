<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Trait\DatetimeTrait;
use Fagathe\CorePhp\Trait\LoggerTrait;
use Fagathe\CorePhp\Uploader\FileUploadException;
use Fagathe\CorePhp\Uploader\UploaderService;
use Fagathe\CorePhp\Uploader\UploadResult;
use Fagathe\GatherCore\Entity\AbstractFile;
use Fagathe\GatherCore\Entity\User;
use LogicException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Point d'entrée unique des fichiers uploadés.
 *
 * La BDD stocke l'URL complète du fichier, construite dès l'upload : gather_core.default_uri (URL publique de
 * l'application qui reçoit le fichier) + chemin relatif à public/. Aucune résolution n'est faite à la lecture.
 */
final class AttachmentFileService
{
    use LoggerTrait, DatetimeTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UploaderService $uploader,
        private readonly string $uploadDir,
        private readonly string $defaultUri,
    ) {}

    /**
     * Upload et remplace l'avatar d'un utilisateur (sans flush). Retourne son URL.
     * Un ancien avatar externe (ex. pravatar) ou servi par une autre application est conservé sur le disque.
     *
     * @throws FileUploadException
     */
    public function uploadAvatar(UploadedFile $file, User $user): string
    {
        // L'ancien avatar n'est supprimé par l'uploader qu'une fois le nouveau déplacé
        $subDir = self::avatarDirectory($user);
        $uploadResult = $this->uploader
            ->setUploadDirectory($subDir)
            ->upload($file, $this->toUploadedPath($user->getAvatar(), $subDir));

        $url = $this->toPublicUrl($uploadResult);
        $user->setAvatar($url)->setUpdatedAt($this->now());

        return $url;
    }

    /**
     * Supprime le fichier de l'avatar d'un utilisateur (s'il a été uploadé sur cette application).
     */
    public function deleteAvatar(User $user): void
    {
        $this->deleteRawPath($user->getAvatar(), self::avatarDirectory($user));
    }

    public function delete(AbstractFile $file, bool $flush = true): bool
    {
        try {
            $this->deleteRawPath($file->getFilePath());

            $this->em->remove($file);
            if ($flush) {
                $this->em->flush();
            }

            $this->generateLog(
                LoggerLevelEnum::Info,
                [
                    'message' => 'Fichier supprimé',
                    'data'    => ['file_id' => (string) $file->getId()],
                ],
                ['action' => 'file.delete.success']
            );

            return true;
        } catch (Throwable $e) {
            $this->generateLog(
                LoggerLevelEnum::Error,
                [
                    'message'   => 'Erreur lors de la suppression du fichier',
                    'data'      => ['file_id' => (string) $file->getId()],
                    'exception' => $e->getMessage(),
                ],
                ['action' => 'file.delete.error']
            );

            return false;
        }
    }

    /**
     * Supprime un fichier uploadé à partir de son URL, s'il se trouve dans {uploadDir}/{subDirectory}/ de cette application.
     */
    public function deleteRawPath(?string $url, string $subDirectory = ''): void
    {
        $relativePath = $this->toUploadedPath($url, $subDirectory);
        if ($relativePath) {
            $this->uploader->delete($relativePath);
        }
    }

    /**
     * URL complète enregistrée en BDD : gather_core.default_uri + chemin relatif à public/.
     */
    private function toPublicUrl(UploadResult $uploadResult): string
    {
        return sprintf('%s/%s', $this->getBaseUri(), ltrim($uploadResult->relativePath, '/'));
    }

    /**
     * Chemin relatif (depuis public/) d'un fichier uploadé sur cette application, uniquement s'il se trouve
     * dans {uploadDir}/{subDirectory}/ ; null sinon (URL externe, fichier d'une autre application ou d'un autre dossier).
     *
     * Empêche de supprimer le fichier d'un autre dossier (ex. l'avatar d'un autre utilisateur),
     * UploaderService::delete() ne contrôlant que l'appartenance au dossier d'upload.
     */
    private function toUploadedPath(?string $url, string $subDirectory): ?string
    {
        $baseUri = $this->getBaseUri() . '/';
        if (!$url || !str_starts_with($url, $baseUri)) {
            return null;
        }

        $path = (string) parse_url(substr($url, strlen($baseUri)), PHP_URL_PATH);
        $prefix = implode('/', array_filter([trim($this->uploadDir, '/'), trim($subDirectory, '/')])) . '/';

        if (!str_starts_with($path, $prefix) || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }

    private function getBaseUri(): string
    {
        $baseUri = rtrim(trim($this->defaultUri), '/');
        if (!str_starts_with($baseUri, 'http://') && !str_starts_with($baseUri, 'https://')) {
            throw new LogicException('gather_core.default_uri (DEFAULT_URI) doit être une URL absolue (ex. https://api.example.com) pour enregistrer les fichiers uploadés.');
        }

        return $baseUri;
    }

    private static function avatarDirectory(User $user): string
    {
        return 'avatars/' . (string) $user->getId();
    }
}
