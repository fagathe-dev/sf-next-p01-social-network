<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Fagathe\GatherCore\Mailer\MailDispatcher;
use Fagathe\GatherCore\Mailer\Preview\MailPreviewInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * Prévisualisation des templates d'e-mails avec des données factices (aucun envoi).
 *
 * L'e-mail est construit par son handler (MailDispatcher::createEmail) puis rendu par le même BodyRenderer
 * que l'envoi réel : l'aperçu correspond exactement au HTML envoyé.
 */
final class EmailPreviewService
{
    use ResponseTrait;

    /** @var array<string, MailPreviewInterface> */
    private readonly array $previews;

    /**
     * @param iterable<string, MailPreviewInterface> $previews indexés par slug
     */
    public function __construct(
        private readonly MailDispatcher $mailDispatcher,
        private readonly MailerInterface $mailer,
        private readonly BodyRendererInterface $bodyRenderer,
        #[AutowireIterator(MailPreviewInterface::TAG, defaultIndexMethod: 'getSlug')]
        iterable $previews,
    ) {
        $this->previews = iterator_to_array($previews);
    }

    /**
     * Liste des e-mails prévisualisables avec leur enveloppe (objet, expéditeur, destinataire).
     */
    public function listPreviews(): object
    {
        $items = [];

        foreach ($this->previews as $slug => $preview) {
            $email = $this->mailDispatcher->createEmail($preview->createDto());

            $items[] = [
                'slug'        => $slug,
                'label'       => $preview->getLabel(),
                'description' => $preview->getDescription(),
                'subject'     => $email->getSubject(),
                'from'        => self::formatAddresses($email->getFrom()),
                'to'          => self::formatAddresses($email->getTo()),
            ];
        }

        return $this->sendJson($items);
    }

    /**
     * HTML rendu de l'e-mail, ou null si le slug est inconnu.
     */
    public function renderPreview(string $slug): ?string
    {
        $preview = $this->previews[$slug] ?? null;
        if ($preview === null) {
            return null;
        }

        $email = $this->mailDispatcher->createEmail($preview->createDto());
        $this->bodyRenderer->render($email);

        return self::htmlBody($email);
    }

    /**
     * Envoie l'e-mail d'exemple à une adresse choisie. Retourne false si le slug est inconnu.
     */
    public function sendTest(string $slug, string $recipient): bool
    {
        $preview = $this->previews[$slug] ?? null;
        if ($preview === null) {
            return false;
        }

        $this->mailer->send($this->mailDispatcher->createEmail($preview->createDto())->to($recipient));

        return true;
    }

    private static function htmlBody(TemplatedEmail $email): string
    {
        $html = $email->getHtmlBody();

        return is_resource($html) ? (string) stream_get_contents($html) : (string) $html;
    }

    /**
     * @param Address[] $addresses
     *
     * @return list<array{name: string|null, email: string}>
     */
    private static function formatAddresses(array $addresses): array
    {
        return array_values(array_map(
            static fn(Address $address): array => [
                'name'  => $address->getName() !== '' ? $address->getName() : null,
                'email' => $address->getAddress(),
            ],
            $addresses
        ));
    }
}
