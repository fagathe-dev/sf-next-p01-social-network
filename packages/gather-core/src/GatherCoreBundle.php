<?php

declare(strict_types=1);

namespace Fagathe\GatherCore;

use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class GatherCoreBundle extends AbstractBundle
{
    /**
     * Configuration `gather_core` de l'application hôte (ex. config/packages/gather_core.yaml) :
     *
     *     gather_core:
     *         default_uri: '%env(DEFAULT_URI)%'
     *         app_url: '%env(default::FRONTEND_URL)%'
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default_uri')
                    ->info('URL publique de l\'application qui reçoit les fichiers uploadés (ex. https://api.example.com). Préfixe l\'URL complète enregistrée en BDD dès l\'upload.')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('app_url')
                    ->info('URL de l\'application ciblée par les liens des e-mails (vérification du compte, mot de passe oublié…). Null : origine de la requête courante.')
                    ->defaultNull()
                ->end()
                ->arrayNode('auth_paths')
                    ->info('Chemins des pages ciblées par les liens des e-mails, relatifs à app_url.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('verify_email')->defaultValue(AuthLinkGenerator::VERIFY_EMAIL_PATH)->end()
                        ->scalarNode('reset_password')->defaultValue(AuthLinkGenerator::RESET_PASSWORD_PATH)->end()
                        ->scalarNode('confirm_email_change')->defaultValue(AuthLinkGenerator::CONFIRM_EMAIL_CHANGE_PATH)->end()
                        ->scalarNode('login')->defaultValue(AuthLinkGenerator::LOGIN_PATH)->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder
    ): void {
        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure()
            // Injecté dans AttachmentFileService : URL complète des fichiers enregistrée dès l'upload
            ->bind('$defaultUri', $config['default_uri'])
            // Injecté dans AuthLinkGenerator pour construire les liens des e-mails d'authentification
            ->bind('$appUrl', $config['app_url'] ?? null)
            // Paramètre déclaré par CorePhpBundle (config/my_uploader.yaml), qui fournit aussi UploaderService
            ->bind('string $uploadDir', '%core_php.uploader.upload_dir%');

        $excludes = [
            __DIR__ . '/Dto',
            __DIR__ . '/Entity',
            __DIR__ . '/Enum',
            __DIR__ . '/GatherCoreBundle.php',
        ];

        // Application sans JWT (ex. gather_admin) : pas d'authentification API
        if (!interface_exists(JWTTokenManagerInterface::class)) {
            $excludes[] = __DIR__ . '/Service/AuthService.php';
            $excludes[] = __DIR__ . '/Security/ApiAuthenticator.php';
        }

        // Chargement automatique des Services et Generators de gather-core
        $services->load('Fagathe\\GatherCore\\', __DIR__ . '/')
            ->exclude($excludes);

        // Chemins des liens d'e-mail propres à l'application hôte (gather_core.auth_paths)
        $services->get(AuthLinkGenerator::class)
            ->arg('$verifyEmailPath', $config['auth_paths']['verify_email'])
            ->arg('$resetPasswordPath', $config['auth_paths']['reset_password'])
            ->arg('$confirmEmailChangePath', $config['auth_paths']['confirm_email_change'])
            ->arg('$loginPath', $config['auth_paths']['login']);
    }
}
