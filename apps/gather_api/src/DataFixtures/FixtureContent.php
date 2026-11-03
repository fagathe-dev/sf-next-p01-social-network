<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * Textes de démonstration : combinés entre eux pour donner des publications variées et crédibles.
 */
final class FixtureContent
{
    /**
     * Thème => communauté, palette, icône, description, lieux, tags, puis les morceaux de publication.
     *
     * @var array<string, array<string, mixed>>
     */
    public const THEMES = [
        'photo' => [
            'name' => 'Photographie', 'palette' => 'indigo', 'icon' => 'camera',
            'description' => 'Partagez vos clichés, vos réglages et vos sorties photo.',
            'places' => ['Quais de Saône, Lyon', 'Vieux-Port, Marseille', 'Mont Saint-Michel', 'Canal Saint-Martin, Paris'],
            'tags' => ['photo', 'argentique', 'portrait', 'paysage', 'goldenhour'],
            'titles' => ['Mes réglages pour la lumière du soir', 'Retour de sortie photo', 'Argentique ou numérique ?'],
            'openers' => ['Lumière dorée ce matin sur les quais.', 'Je viens de développer ma dernière pellicule.', 'Nouvel objectif reçu cette semaine.', 'Sortie photo sous la pluie, et je ne regrette rien.', 'Je retravaille mes vieux clichés de vacances.', 'Première séance de portraits en extérieur.'],
            'details' => ['Le 35 mm reste mon focale préférée pour la rue.', 'Ouverture à f/2.8, ça détache très bien le sujet.', 'Les couleurs sortent presque sans retouche.', "J'ai enfin compris l'intérêt d'un filtre polarisant.", 'Le grain de cette pellicule est magnifique.', 'Se lever à 6 h valait vraiment le coup.'],
            'questions' => ['Vous shootez plutôt en RAW ou en JPEG ?', 'Qui serait partant pour une sortie samedi ?', 'Une idée de lieu à recommander ?', 'Vous retouchez avec quel logiciel ?'],
        ],
        'lifestyle' => [
            'name' => 'Lifestyle', 'palette' => 'peach', 'icon' => 'coffee',
            'description' => 'Café, art de vivre et petits plaisirs du quotidien.',
            'places' => ['Café de la Gare, Nantes', 'Marché des Lices, Rennes', 'Bordeaux centre', 'Le Marais, Paris'],
            'tags' => ['cafe', 'slowlife', 'dimanche', 'routine'],
            'titles' => ['Ma routine du dimanche', 'Le café parfait existe-t-il ?'],
            'openers' => ['Mon café du dimanche : V60, 15 g pour 250 ml.', 'Nouvelle adresse découverte près de chez moi.', 'Je teste une routine sans écran le matin.', 'Petit-déjeuner au soleil pour bien commencer.', 'Grand rangement de printemps terminé.'],
            'details' => ['Les grains viennent d\'un petit torréfacteur local.', 'Ça change vraiment le rythme de la journée.', 'Le lieu est calme, parfait pour lire une heure.', 'Moins de choses, plus de temps pour soi.', 'Je ne pensais pas que ça ferait autant de bien.'],
            'questions' => ['Vous avez une adresse à partager ?', 'Quel est votre rituel du matin ?', 'Vous tenez combien de temps sans téléphone ?'],
        ],
        'rando' => [
            'name' => 'Randonnée', 'palette' => 'mint', 'icon' => 'mountain',
            'description' => 'Sentiers, bivouacs et grands espaces.',
            'places' => ['Vercors', 'Lac Blanc, Chamonix', 'Gorges du Verdon', 'Cirque de Gavarnie'],
            'tags' => ['randonnee', 'bivouac', 'montagne', 'nature'],
            'titles' => ['Bivouac réussi au bord du lac', 'Mon sac pour trois jours en autonomie'],
            'openers' => ['Nouveau sentier repéré dans le Vercors, 14 km.', 'Premier bivouac de la saison ce week-end.', 'Montée raide mais vue incroyable au sommet.', 'Je prépare un GR pour cet été.', 'Grosse journée : 1 200 m de dénivelé.'],
            'details' => ['Pensez à prendre de l\'eau, aucune source sur le parcours.', 'Le sac reste sous les 9 kg, je suis fier de moi.', 'Quelques passages enneigés encore en altitude.', 'On a croisé deux chamois au lever du jour.', 'La météo a tenu jusqu\'au retour au parking.'],
            'questions' => ['Quelqu\'un a déjà fait ce tronçon ?', 'Vous partez avec quelle tente ?', 'Une boucle à conseiller pour débuter ?'],
        ],
        'musique' => [
            'name' => 'Musique', 'palette' => 'rose', 'icon' => 'music',
            'description' => 'Concerts, vinyles et découvertes.',
            'places' => ['La Cigale, Paris', 'Le Transbordeur, Lyon', 'Festival de Nîmes', 'Le Bikini, Toulouse'],
            'tags' => ['vinyle', 'musique', 'concert', 'jazz'],
            'titles' => ['Ma sélection vinyle du mois', 'Concert de la semaine'],
            'openers' => ['Le pressage de cet album est une merveille.', 'Concert incroyable hier soir.', 'Je redécouvre le jazz des années 60.', 'Nouvelle platine installée à la maison.', 'Je me suis remis à la guitare.'],
            'details' => ['Écoute au casque obligatoire pour les arrangements.', 'La salle était petite, l\'ambiance parfaite.', 'Chaque écoute révèle un nouveau détail.', 'Le son est bien plus chaud qu\'en streaming.', 'Trente minutes par jour, et ça progresse.'],
            'questions' => ['Votre album de l\'année pour l\'instant ?', 'Vous achetez encore des disques ?', 'Une découverte à me conseiller ?'],
        ],
        'cuisine' => [
            'name' => 'Cuisine maison', 'palette' => 'amber', 'icon' => 'utensils',
            'description' => 'Recettes, astuces et plats du quotidien.',
            'places' => ['Halles de Lyon', 'Marché de Sanary', 'Toulouse'],
            'tags' => ['recette', 'cuisine', 'patisserie', 'levain'],
            'titles' => ['Mon pain au levain, enfin réussi', 'Une recette de saison'],
            'openers' => ['Quatrième essai de pain au levain, enfin une belle mie.', 'Recette du jour : gratin de courge et noisettes.', 'Je me lance dans les pâtes fraîches.', 'Tarte aux pommes de ma grand-mère ce soir.', 'Batch cooking terminé pour la semaine.'],
            'details' => ['Le secret, c\'est une longue fermentation au frais.', 'Moins de sucre que la recette d\'origine, et c\'est meilleur.', 'Vingt minutes de préparation, pas plus.', 'Toute la famille a redemandé une part.', 'Les produits viennent du marché du coin.'],
            'questions' => ['Vous avez une variante à proposer ?', 'Quelle est votre recette fétiche ?', 'Une astuce pour éviter que ça colle ?'],
        ],
        'lecture' => [
            'name' => 'Club de lecture', 'palette' => 'indigo', 'icon' => 'book',
            'description' => 'Romans, essais et piles à lire.',
            'places' => ['Médiathèque de Nantes', 'Librairie Mollat, Bordeaux'],
            'tags' => ['lecture', 'roman', 'polar', 'pal'],
            'titles' => ['Mon coup de cœur du mois', 'Ma pile à lire de l\'été'],
            'openers' => ['Je viens de finir ce roman en deux soirées.', 'Ma pile à lire ne fait que grandir.', 'Je relis un classique du lycée.', 'Premier polar nordique pour moi.', 'Découverte en librairie un peu par hasard.'],
            'details' => ['La dernière partie m\'a laissé sans voix.', 'Le style est sobre, l\'histoire captivante.', 'Je ne l\'avais pas du tout compris à l\'époque.', 'Les personnages sont d\'une grande justesse.', 'À lire d\'une traite, sans regarder l\'heure.'],
            'questions' => ['Vous l\'avez lu ? Votre avis ?', 'Quel livre vous a marqué récemment ?', 'Papier ou liseuse ?'],
        ],
        'jardin' => [
            'name' => 'Jardin & potager', 'palette' => 'mint', 'icon' => 'sprout',
            'description' => 'Semis, récoltes et conseils de saison.',
            'places' => ['Angers', 'Dordogne', 'Lubéron'],
            'tags' => ['jardin', 'potager', 'semis', 'compost'],
            'titles' => ['Bilan du potager', 'Mes semis de printemps'],
            'openers' => ['Premières tomates de la saison au balcon.', 'Mes semis commencent enfin à lever.', 'Grosse récolte de courgettes cette semaine.', 'J\'ai installé un composteur au fond du jardin.', 'Les pucerons ont attaqué mes rosiers.'],
            'details' => ['Le paillage a vraiment limité l\'arrosage.', 'Un peu de savon noir a réglé le problème.', 'Trois semaines d\'avance sur l\'an dernier.', 'Mes enfants adorent aller vérifier chaque matin.', 'Le sol s\'améliore d\'année en année.'],
            'questions' => ['Vous plantez quoi en ce moment ?', 'Une variété facile à conseiller ?', 'Comment protégez-vous vos semis ?'],
        ],
        'cinema' => [
            'name' => 'Ciné & séries', 'palette' => 'rose', 'icon' => 'film',
            'description' => 'Critiques, recommandations et débats.',
            'places' => ['Le Grand Rex, Paris', 'Cinéma Comœdia, Lyon'],
            'tags' => ['cinema', 'serie', 'film', 'critique'],
            'titles' => ['Le film à voir ce mois-ci', 'Une série sous-estimée'],
            'openers' => ['Vu hier en salle, et quelle claque.', 'Je viens de terminer cette série d\'une traite.', 'Soirée rétro : un classique des années 80.', 'Avant-première à côté de chez moi ce soir.', 'Je redonne une chance à ce réalisateur.'],
            'details' => ['La photographie est sublime du début à la fin.', 'La fin divise, mais elle me convainc.', 'Le rythme est lent et ça lui va très bien.', 'La bande originale reste en tête pendant des jours.', 'Le casting porte vraiment le film.'],
            'questions' => ['Vous en avez pensé quoi ?', 'Une recommandation pour ce week-end ?', 'Plutôt VO ou VF ?'],
        ],
        'jeux' => [
            'name' => 'Jeux vidéo', 'palette' => 'indigo', 'icon' => 'gamepad',
            'description' => 'Découvertes, soirées coop et jeux indépendants.',
            'places' => ['Paris Games Week', 'Montpellier'],
            'tags' => ['jeuxvideo', 'indie', 'coop', 'retrogaming'],
            'titles' => ['Le jeu indé de l\'année', 'Soirée coop du vendredi'],
            'openers' => ['Soixante heures sur ce jeu indé, je ne décroche pas.', 'Soirée coop réussie hier avec les copains.', 'Je ressors ma vieille console du placard.', 'Le dernier patch change complètement le jeu.', 'Enfin terminé à 100 %.'],
            'details' => ['La direction artistique est superbe.', 'Le dernier boss m\'a donné du fil à retordre.', 'Parfait par petites sessions le soir.', 'La bande-son mérite à elle seule le détour.', 'Tout passe bien, même sur une petite machine.'],
            'questions' => ['Vous jouez à quoi en ce moment ?', 'Quelqu\'un pour une partie ce soir ?', 'Un jeu coop à conseiller ?'],
        ],
        'velo' => [
            'name' => 'Vélo', 'palette' => 'amber', 'icon' => 'bike',
            'description' => 'Vélotaf, gravel et voyages à vélo.',
            'places' => ['Via Rhôna', 'Loire à Vélo', 'Col du Galibier'],
            'tags' => ['velo', 'gravel', 'velotaf', 'cyclotourisme'],
            'titles' => ['Mon premier voyage à vélo', 'Un an de vélotaf : le bilan'],
            'openers' => ['Soixante kilomètres ce matin sur la Via Rhôna.', 'Un an de vélotaf, et je ne reviendrai pas en arrière.', 'Nouveau gravel en test ce week-end.', 'Crevaison sous la pluie, journée complète.', 'Je prépare une semaine de cyclotourisme.'],
            'details' => ['Les sacoches tiennent parfaitement.', 'Vingt minutes de gagnées chaque matin.', 'Le pneu de 40 mm change tout sur les chemins.', 'Pensez à une chambre à air de rechange.', 'Les pistes s\'améliorent nettement en ville.'],
            'questions' => ['Vous roulez avec quel équipement ?', 'Une véloroute à conseiller ?', 'Vous roulez aussi l\'hiver ?'],
        ],
        'voyage' => [
            'name' => 'Voyages', 'palette' => 'peach', 'icon' => 'plane',
            'description' => 'Carnets de route et bonnes adresses.',
            'places' => ['Lisbonne', 'Kyoto', 'Reykjavik', 'Marrakech'],
            'tags' => ['voyage', 'roadtrip', 'carnet', 'europe'],
            'titles' => ['Carnet de route : dix jours au Portugal', 'Voyager léger, mes conseils'],
            'openers' => ['Retour de dix jours à Lisbonne.', 'Road trip en préparation pour septembre.', 'Je voyage désormais avec un seul sac.', 'Quelle ville surprenante, on y retournera.', 'Premier voyage en train de nuit.'],
            'details' => ['Le quartier est bien plus calme hors saison.', 'Tout s\'est fait en transports en commun.', 'Les petits restaurants valent tous les guides.', 'Partir en mai évite la foule et la chaleur.', 'Le trajet a finalement fait partie du voyage.'],
            'questions' => ['Une adresse à ne pas manquer ?', 'Vous préparez tout ou improvisez ?', 'Votre prochaine destination ?'],
        ],
        'bricolage' => [
            'name' => 'Bricolage', 'palette' => 'peach', 'icon' => 'hammer',
            'description' => 'Rénovation, récup et projets maison.',
            'places' => ['Reims', 'Strasbourg'],
            'tags' => ['bricolage', 'renovation', 'recup', 'bois'],
            'titles' => ['Rénovation de ma table en chêne', 'Un meuble à partir de palettes'],
            'openers' => ['Table en chêne décapée et huilée ce week-end.', 'Étagères sur mesure terminées dans le salon.', 'Je rénove une vieille commode trouvée en brocante.', 'Premier essai de carrelage, sois indulgent.', 'Atelier enfin rangé après trois ans.'],
            'details' => ['Le ponçage a pris plus longtemps que prévu.', 'Moins de 80 € de matériel au total.', 'Prenez le temps de bien mesurer deux fois.', 'L\'huile dure protège et garde le veinage.', 'Le résultat dépasse ce que j\'espérais.'],
            'questions' => ['Vous auriez fini comment ?', 'Quelle huile utilisez-vous ?', 'Un outil indispensable à me conseiller ?'],
        ],
    ];

    /** Communautés privées, rattachées à un thème pour leurs contenus. */
    public const PRIVATE_GROUPS = [
        ['name' => 'Cercle argentique', 'theme' => 'photo', 'palette' => 'amber', 'icon' => 'aperture', 'description' => 'Groupe privé autour de la photo argentique.'],
        ['name' => 'Coureurs de bivouac', 'theme' => 'rando', 'palette' => 'mint', 'icon' => 'mountain', 'description' => 'Sorties entre habitués, sur validation.'],
        ['name' => 'Lecteurs du jeudi', 'theme' => 'lecture', 'palette' => 'rose', 'icon' => 'book', 'description' => 'Club de lecture privé, une rencontre par mois.'],
    ];

    public const BIOS = [
        'Curieuse de tout, surtout le week-end.', 'Toujours un projet en cours.', 'Je partage ce que j\'aime, sans prétention.',
        'Amateur éclairé et éternel débutant.', 'Entre deux trains, souvent un livre à la main.', 'Ici pour apprendre et échanger.',
        'Passionné par les choses bien faites.', 'Je poste peu, mais je lis tout.', 'Le dimanche, je déconnecte. Enfin, j\'essaie.',
    ];

    public const COMMENTS = [
        'Superbe, merci du partage !', 'Je note ça pour le week-end prochain.', 'Tout à fait d\'accord avec toi.', 'Tu as utilisé quoi exactement ?',
        'J\'ai essayé la même chose le mois dernier, pas facile.', 'Ça donne vraiment envie.', 'Bravo, le résultat est top.', 'On en reparle samedi ?',
        'Merci pour le conseil, ça m\'a bien aidé.', 'Pas convaincu, mais je veux bien essayer.', 'Je ne connaissais pas du tout, je regarde ça.', 'Excellente idée !',
    ];

    public const MESSAGES = [
        'Salut, tu vas bien ?', 'Tu as vu ma dernière publication ?', 'On se voit samedi finalement ?', 'Merci pour ton conseil, ça a marché !',
        'Je t\'envoie les détails ce soir.', 'Super, ça me va.', 'Tu viens à la sortie de dimanche ?', 'Désolé pour le retard de réponse.',
        'Je passe te voir en fin de semaine.', 'Bonne idée, je m\'en occupe.',
    ];

    public const TICKETS = [
        ['bug_report', 'Impossible d\'envoyer une photo', 'L\'envoi échoue dès que l\'image dépasse quelques Mo.'],
        ['bug_report', 'Les notifications ne s\'actualisent pas', 'Le compteur reste bloqué jusqu\'au rechargement de la page.'],
        ['account_issue', 'Je ne reçois pas le lien de vérification', 'Rien dans ma boîte, même dans les indésirables.'],
        ['account_issue', 'Changement d\'adresse e-mail', 'Je n\'ai plus accès à mon ancienne adresse.'],
        ['moderation_appeal', 'Contestation d\'une suppression', 'Ma publication a été retirée alors qu\'elle respecte les règles.'],
        ['moderation_appeal', 'Sanction que je ne comprends pas', 'Mon compte est en lecture seule sans explication claire.'],
        ['privacy_gdpr', 'Demande d\'export de mes données', 'Je souhaite récupérer une copie de mes données personnelles.'],
        ['privacy_gdpr', 'Suppression de mes publications', 'Comment effacer l\'ensemble de mes anciens contenus ?'],
        ['other', 'Proposition de nouvelle communauté', 'Serait-il possible de créer une rubrique dédiée à la céramique ?'],
        ['other', 'Question sur les collections', 'Peut-on partager une collection avec une seule personne ?'],
    ];

    public const STAFF_REPLIES = [
        'Bonjour, merci pour votre message, nous regardons cela.', 'Pouvez-vous nous préciser votre navigateur ?',
        'Le problème devrait être résolu, pouvez-vous réessayer ?', 'Nous avons bien pris en compte votre demande.',
    ];

    public const INTERNAL_NOTES = ['À vérifier avec l\'équipe technique.', 'Déjà signalé la semaine dernière par un autre membre.', 'Compte à surveiller, plusieurs signalements récents.'];

    public const REPORT_DETAILS = ['Publication répétée plusieurs fois.', 'Propos déplacés envers un membre.', 'Ressemble à de la publicité déguisée.', 'Information manifestement fausse.', null, null];

    public const LINKS = [
        ['https://example.com/guide-photo-de-rue', 'example.com', 'article', 'Guide de la photo de rue', 'Dix conseils pour progresser en photographie urbaine.', 'Le Mag Photo'],
        ['https://example.org/sentiers-vercors', 'example.org', 'article', 'Les plus beaux sentiers du Vercors', 'Itinéraires, dénivelés et bonnes adresses.', 'Rando Mag'],
        ['https://example.net/pain-levain', 'example.net', 'article', 'Réussir son levain en sept jours', 'La méthode pas à pas, sans matériel particulier.', 'Cuisine & Co'],
        ['https://example.com/vinyles-2026', 'example.com', 'website', 'Les vinyles à écouter cette année', 'Notre sélection de pressages à ne pas manquer.', 'Sillons'],
    ];
}
