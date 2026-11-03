<?php

namespace Fagathe\GatherCore\Dto\Account;

use Fagathe\GatherCore\Dto\Common\SafeImageMimeTypes;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class UploadAvatarDto
{
    /**
     * Sans image/svg+xml (voir SafeImageMimeTypes)
     */
    public const ALLOWED_MIME_TYPES = SafeImageMimeTypes::ALL;

    public function __construct(
        #[Assert\NotNull(message: 'Veuillez sélectionner une image.')]
        #[Assert\File(
            maxSize: '5M',
            mimeTypes: self::ALLOWED_MIME_TYPES,
            maxSizeMessage: 'L\'image ne doit pas dépasser 5 Mo.',
            mimeTypesMessage: 'Seuls les formats JPEG, PNG, WEBP et GIF sont acceptés pour l\'avatar.'
        )]
        public readonly ?UploadedFile $file = null,
    ) {}
}
