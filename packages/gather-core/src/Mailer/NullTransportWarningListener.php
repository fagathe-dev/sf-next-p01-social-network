<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Trait\LoggerTrait;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;

/**
 * Le transport null:// (MAILER_DSN=null://null, valeur par défaut de .env) jette l'e-mail sans erreur :
 * AbstractMailerHandler journalise alors « envoyé » alors que rien n'est parti. Cet avertissement le signale.
 */
#[AsEventListener]
final class NullTransportWarningListener
{
    use LoggerTrait;

    public function __invoke(MessageEvent $event): void
    {
        // Événement émis par le transport lui-même (le Mailer en émet un premier, avec le transport agrégé)
        if ($event->isQueued() || !str_starts_with($event->getTransport(), 'null://')) {
            return;
        }

        $message = $event->getMessage();

        $this->generateLog(
            LoggerLevelEnum::Warning,
            [
                'message' => 'E-mail non envoyé : MAILER_DSN utilise le transport null:// (configurer MAILER_DSN dans .env.local).',
                'data'    => [
                    'subject'    => $message instanceof Message ? $message->getHeaders()->getHeaderBody('Subject') : null,
                    'recipients' => array_map(
                        static fn(Address $address): string => $address->getAddress(),
                        $event->getEnvelope()->getRecipients()
                    ),
                ],
            ],
            ['action' => 'mailer.null_transport']
        );
    }
}
