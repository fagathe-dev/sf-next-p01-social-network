<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Fagathe\CorePhp\Mailer\Exception\MailerExceptionInterface;
use Fagathe\CorePhp\Trait\LoggerTrait;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Throwable;

/**
 * Point d'entrée unique d'envoi des e-mails : retrouve le handler correspondant au DTO.
 */
final class MailDispatcher
{
    use LoggerTrait;

    public function __construct(
        #[AutowireLocator(MailHandlerInterface::TAG, defaultIndexMethod: 'getDtoClass')]
        private readonly ContainerInterface $handlers,
    ) {}

    /**
     * @throws MailerExceptionInterface
     */
    public function dispatch(object $dto): void
    {
        $this->resolveHandler($dto)->send($dto);
    }

    /**
     * Envoi non bloquant : aucun échec (configuration, transport…) ne remonte à l'appelant.
     */
    public function dispatchSilently(object $dto): bool
    {
        try {
            $this->resolveHandler($dto)->send($dto);

            return true;
        } catch (MailerExceptionInterface) {
            // Déjà journalisé par AbstractMailerHandler
            return false;
        } catch (Throwable $e) {
            $this->generateLog(
                LoggerLevelEnum::Error,
                [
                    'message'   => 'Échec inattendu lors de l\'envoi silencieux d\'un e-mail',
                    'data'      => ['dto' => $dto::class],
                    'exception' => $e,
                ],
                ['action' => 'mailer.dispatch_silently.error']
            );

            return false;
        }
    }

    /**
     * Construit l'e-mail sans l'envoyer (prévisualisation, tests).
     */
    public function createEmail(object $dto): TemplatedEmail
    {
        return $this->resolveHandler($dto)->createEmail($dto);
    }

    /**
     * @return AbstractMailerHandler<object>
     */
    private function resolveHandler(object $dto): AbstractMailerHandler
    {
        if (!$this->handlers->has($dto::class)) {
            throw new InvalidArgumentException(sprintf(
                'Aucun handler (%s) ne prend en charge le DTO "%s".',
                MailHandlerInterface::class,
                $dto::class
            ));
        }

        /** @var AbstractMailerHandler<object> $handler */
        $handler = $this->handlers->get($dto::class);

        return $handler;
    }
}
