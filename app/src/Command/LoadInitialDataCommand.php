<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Discipline;
use App\Entity\ResourceType;
use App\Repository\DisciplineRepository;
use App\Repository\ResourceTypeRepository;
use App\Service\ResourceTypeMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande pour charger les données initiales de la plateforme.
 *
 * Charge les types de ressources et les disciplines artistiques
 * si elles n'existent pas encore en base (idempotente — peut être relancée sans risque).
 *
 * Usage : docker compose exec app php bin/console app:load-initial-data
 */
#[AsCommand(
    name: 'app:load-initial-data',
    description: 'Charge les types de ressources et disciplines artistiques initiaux.',
)]
class LoadInitialDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResourceTypeRepository $typeRepository,
        private readonly DisciplineRepository $disciplineRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Chargement des données initiales BazaArt');

        // --- Types de ressources ---
        // Chaque type correspond à une catégorie d'opportunité sur la plateforme.
        //
        // ⚠️ RÉFÉRENTIEL UNIFIÉ (correction lot bugfix) : cette liste vivait avant
        // en double ici ET dans AppFixtures::createResourceTypes(), avec des noms
        // LÉGÈREMENT différents ("Financement" ici vs "Bourse & Financement" dans
        // les fixtures, "Prix & concours" vs "Prix & Concours"...). Cette divergence
        // cassait le mapping LLM → ResourceType (ResourceTypeMapper::mapLabelToType())
        // et le résolveur de GrantCsvImporter, qui s'attendent tous deux aux noms
        // canoniques définis UNE SEULE FOIS dans ResourceTypeMapper::CANONICAL_TYPES.
        $resourceTypes = ResourceTypeMapper::CANONICAL_TYPES;

        $typesCreated = 0;
        foreach ($resourceTypes as $name => $icon) {
            // findOneBy pour éviter les doublons si la commande est relancée
            $existing = $this->typeRepository->findOneBy(['name' => $name]);
            if ($existing === null) {
                $type = new ResourceType();
                $type->setName($name);
                $type->setIcon($icon);
                $this->em->persist($type);
                $typesCreated++;
            }
        }

        // --- Disciplines artistiques ---
        // Couvrent les principaux domaines présents sur la plateforme.

        $disciplines = [
            ['name' => 'Musique',              'icon' => '🎵'],
            ['name' => 'Arts visuels',         'icon' => '🎨'],
            ['name' => 'Théâtre',              'icon' => '🎭'],
            ['name' => 'Danse',                'icon' => '💃'],
            ['name' => 'Cinéma & vidéo',       'icon' => '🎬'],
            ['name' => 'Littérature & poésie', 'icon' => '📚'],
            ['name' => 'Architecture',         'icon' => '🏛️'],
            ['name' => 'Design',               'icon' => '✏️'],
            ['name' => 'Photographie',         'icon' => '📷'],
            ['name' => 'Arts numériques',      'icon' => '💻'],
            ['name' => 'Cirque & arts de rue', 'icon' => '🎪'],
            ['name' => 'Mode & textile',       'icon' => '👗'],
            ['name' => 'BD & illustration',    'icon' => '🖊️'],
            ['name' => 'Art sonore',           'icon' => '🔊'],
            ['name' => 'Pluridisciplinaire',   'icon' => '🌐'],
        ];

        $disciplinesCreated = 0;
        foreach ($disciplines as $data) {
            $existing = $this->disciplineRepository->findOneBy(['name' => $data['name']]);
            if ($existing === null) {
                $discipline = new Discipline();
                $discipline->setName($data['name']);
                $discipline->setIcon($data['icon']);
                $this->em->persist($discipline);
                $disciplinesCreated++;
            }
        }

        // Un seul flush à la fin pour optimiser les requêtes
        $this->em->flush();

        $io->success(sprintf(
            '%d type(s) de ressources et %d discipline(s) créé(e)s.',
            $typesCreated,
            $disciplinesCreated
        ));

        if ($typesCreated === 0 && $disciplinesCreated === 0) {
            $io->note('Toutes les données existent déjà en base. Rien à faire.');
        }

        return Command::SUCCESS;
    }
}
