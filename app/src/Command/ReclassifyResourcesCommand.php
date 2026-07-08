<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Resource;
use App\Repository\ResourceRepository;
use App\Repository\ScrapedResourceRepository;
use App\Service\DeadlineParserService;
use App\Service\ResourceTypeMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ReclassifyResourcesCommand — Commande de rattrapage pour les Resource déjà
 * publiées dont le ResourceType ou la deadline pourraient être erronés à cause
 * des bugs corrigés dans ce même lot :
 *
 *   1. AdminController::verifyScrapedOpportunity() utilisait avant un fallback
 *      findOneBy(['name' => $scraped->getType()]) qui échouait presque
 *      systématiquement (le libellé LLM ne correspond jamais exactement au nom
 *      canonique) → retombait sur findAll()[0], un ResourceType ARBITRAIRE.
 *   2. DeadlineParserService ne bornait pas la plausibilité des années
 *      → une deadline mal extraite pouvait finir en 2020 (ou 2099) en base.
 *
 * Ces deux bugs sont corrigés à la source (ResourceTypeMapper, borne de
 * plausibilité), mais les Resource déjà publiées AVANT le correctif peuvent
 * porter les valeurs erronées. Cette commande les rattrape.
 *
 * ── LIMITE IMPORTANTE : COMMENT LE "RECALCUL" EST POSSIBLE ───────────────────
 * Resource ne conserve AUCUNE trace du libellé de type brut d'origine (pas de
 * champ "type" texte, pas de FK vers la ScrapedResource dont elle est issue).
 * On ne peut donc PAS recalculer le ResourceType "depuis rien".
 *
 * La seule source de vérité disponible est un rapprochement IMPLICITE par URL :
 * AdminController::verifyScrapedOpportunity() copie toujours
 * $scraped->getUrl() → $resource->setExternalUrl(...). On peut donc retrouver
 * la ScrapedResource d'origine via ScrapedResourceRepository::findByUrl() et
 * relire son libellé de type brut ($scraped->getType()).
 *
 * CAS NON COUVERTS (signalés, jamais modifiés silencieusement) :
 *   - Resource sans externalUrl (soumission manuelle artiste/structure/admin,
 *     jamais issue du scraping) → pas de source pour revalider le type.
 *   - Resource avec externalUrl mais dont la ScrapedResource source a depuis
 *     été supprimée ou dont l'URL ne correspond plus exactement (normalisation
 *     d'URL différente).
 *   - Resource avec externalUrl mais scraped->getType() vide.
 *   Dans tous ces cas, le ResourceType existant n'est PAS modifié — la ligne
 *   est simplement listée dans le rapport sous "type non vérifiable".
 *
 * ⚠️ DÉCISION À VALIDER AVEC GAËLLE : si le volume de "type non vérifiable"
 * est important, il faudra soit ajouter une FK Resource → ScrapedResource
 * (V2, hors scope du délai V1), soit accepter que ces Resource ne soient
 * jamais auditées automatiquement.
 *
 * ── DEADLINE ──────────────────────────────────────────────────────────────
 * Une deadline déjà stockée est auditée via DeadlineParserService::isDatePlausible().
 * Si elle est hors bornes (ex: année 2020) :
 *   - Si la ScrapedResource source est retrouvée ET que son champ deadline
 *     (texte brut) reparse en une date plausible → on corrige avec cette date.
 *   - Sinon → on met la deadline à null (une deadline aberrante non corrigeable
 *     est pire qu'une absence de deadline : elle peut fausser le tri "urgence"
 *     du catalogue public ou masquer la ressource via hideExpired).
 *
 * ── MODE D'EXÉCUTION ──────────────────────────────────────────────────────
 *   --dry-run (défaut implicite) : affiche le rapport, n'écrit rien en base.
 *   --force                      : applique réellement les corrections.
 *
 * Lancement (à faire manuellement, jamais via cron) :
 *   docker compose exec app php bin/console app:reclassify-resources
 *   docker compose exec app php bin/console app:reclassify-resources --force
 */
#[AsCommand(
    name: 'app:reclassify-resources',
    description: 'Audite (et corrige avec --force) le ResourceType et la deadline des Resource publiées, à la suite du correctif ResourceTypeMapper / DeadlineParserService.',
)]
class ReclassifyResourcesCommand extends Command
{
    public function __construct(
        private readonly ResourceRepository $resourceRepository,
        private readonly ScrapedResourceRepository $scrapedResourceRepository,
        private readonly ResourceTypeMapper $resourceTypeMapper,
        private readonly DeadlineParserService $deadlineParser,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Applique réellement les corrections (par défaut : dry-run, rien n\'est écrit)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Force explicitement le mode dry-run (comportement déjà par défaut sans --force)'
            )
            ->setHelp(<<<'HELP'
Cette commande est un <info>rattrapage manuel</info>, à lancer ponctuellement en
production après le déploiement du correctif ResourceTypeMapper / DeadlineParserService.

<comment>Ce qu'elle fait :</comment>
  1. Parcourt toutes les Resource au statut "Publiée".
  2. Pour chaque Resource ayant une externalUrl, tente de retrouver la
     ScrapedResource d'origine (par URL) pour :
       - revalider/corriger son ResourceType via ResourceTypeMapper
       - revalider/corriger sa deadline via DeadlineParserService
  3. Sans --force : affiche uniquement un rapport (dry-run).
  4. Avec --force : applique les corrections et flush en base.

<comment>Limites :</comment> les Resource sans externalUrl (soumissions manuelles)
ne peuvent pas être revalidées automatiquement — elles sont signalées, jamais
modifiées à l'aveugle.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --force applique, --dry-run (ou l'absence de --force) n'écrit rien.
        $isForce  = (bool) $input->getOption('force');
        $isDryRun = !$isForce || (bool) $input->getOption('dry-run');

        $io->title('Rattrapage des Resource publiées — ResourceType & Deadline');

        if ($isDryRun) {
            $io->note('Mode DRY-RUN actif : aucune écriture en base de données. Relancer avec --force pour appliquer.');
        } else {
            $io->warning('Mode --force actif : les corrections vont être écrites en base.');
        }

        // ── Chargement des candidats ──────────────────────────────────────────
        // hideExpired: false → on veut auditer TOUTES les publiées, y compris
        // celles dont la deadline serait déjà (à raison) passée.
        // excludeDocumentation: false → on veut aussi auditer les "Documentation".
        /** @var Resource[] $resources */
        $resources = $this->resourceRepository->findPublished(hideExpired: false);

        $total = count($resources);
        if ($total === 0) {
            $io->success('Aucune ressource publiée à auditer.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('<info>%d ressource(s) publiée(s) à auditer</info>', $total));
        $io->newLine();

        $rows                = [];
        $typeCorrected       = 0;
        $typeUnverifiable    = 0;
        $deadlineCorrected   = 0;
        $batchSize           = 50;

        foreach ($resources as $i => $resource) {
            // ── Retrouver la ScrapedResource d'origine via l'URL externe ──────
            // C'est le SEUL lien disponible (voir docblock de classe) : Resource
            // n'a pas de FK directe vers ScrapedResource.
            $linkedScraped = null;
            $externalUrl   = $resource->getExternalUrl();
            if ($externalUrl !== null && $externalUrl !== '') {
                $linkedScraped = $this->scrapedResourceRepository->findByUrl($externalUrl);
            }

            $rowNotes = [];

            // ── 1. Audit du ResourceType ───────────────────────────────────────
            $rawTypeLabel = $linkedScraped?->getType();

            if ($rawTypeLabel !== null && trim($rawTypeLabel) !== '') {
                $suggestedType = $this->resourceTypeMapper->mapLabelToType($rawTypeLabel);
                $currentType   = $resource->getResourceType();

                if ($suggestedType->getId() !== $currentType->getId()) {
                    $rowNotes[] = sprintf(
                        'Type : "%s" → "%s" (libellé source : "%s")',
                        $currentType->getName(),
                        $suggestedType->getName(),
                        $rawTypeLabel
                    );

                    if (!$isDryRun) {
                        $resource->setResourceType($suggestedType);
                    }
                    $typeCorrected++;
                }
            } else {
                // Aucune source retrouvée pour revalider le type : on SIGNALE,
                // on ne touche à rien. Le type existant reste en l'état.
                $rowNotes[]      = 'Type : non vérifiable (pas de ScrapedResource source retrouvée via externalUrl)';
                $typeUnverifiable++;
            }

            // ── 2. Audit de la deadline ────────────────────────────────────────
            $currentDeadline = $resource->getDeadline();

            if ($currentDeadline !== null && !$this->deadlineParser->isDatePlausible($currentDeadline)) {
                // Deadline hors bornes de plausibilité (ex: année 2020) — on tente
                // de la corriger depuis la ScrapedResource source, sinon on l'efface.
                $newDeadline    = null;
                $rawDeadlineTxt = $linkedScraped?->getDeadline();

                if ($rawDeadlineTxt !== null && $rawDeadlineTxt !== '') {
                    $newDeadline = $this->deadlineParser->parse($rawDeadlineTxt);
                }

                $rowNotes[] = sprintf(
                    'Deadline : %s → %s%s',
                    $currentDeadline->format('Y-m-d'),
                    $newDeadline !== null ? $newDeadline->format('Y-m-d') : 'null',
                    $newDeadline === null ? ' (non re-parseable, effacée par prudence)' : ''
                );

                if (!$isDryRun) {
                    // Resource::deadline est un champ Doctrine 'date' (DateTime MUTABLE) ;
                    // parse() renvoie un DateTimeImmutable → conversion obligatoire avant
                    // setDeadline, sinon InvalidType au flush.
                    $resource->setDeadline(
                        $newDeadline instanceof \DateTimeImmutable
                            ? \DateTime::createFromInterface($newDeadline)
                            : $newDeadline
                    );
                }
                $deadlineCorrected++;
            }

            // ── Ligne de rapport (uniquement si une action a été identifiée) ──
            if ($rowNotes !== []) {
                $rows[] = [
                    $resource->getId(),
                    mb_substr($resource->getTitle(), 0, 50),
                    implode("\n", $rowNotes),
                ];
            }

            // Flush par lots pour éviter une transaction unique trop longue.
            // Pas de em->clear() : voir le commentaire équivalent dans
            // BackfillDeadlineDateCommand (même raisonnement, table de taille modeste).
            if (!$isDryRun && ($i + 1) % $batchSize === 0) {
                $this->em->flush();
            }
        }

        if (!$isDryRun) {
            $this->em->flush();
        }

        // ── Rapport détaillé ────────────────────────────────────────────────
        if ($rows !== []) {
            $io->table(['ID', 'Titre', 'Corrections identifiées'], $rows);
        } else {
            $io->text('Aucune anomalie détectée sur les ressources auditées.');
        }

        $io->newLine();
        $io->table(
            ['Catégorie', 'Nombre'],
            [
                ['Ressources auditées', $total],
                ['Types corrigés' . ($isDryRun ? ' (dry-run — à corriger avec --force)' : ''), $typeCorrected],
                ['Types non vérifiables (signalés, non modifiés)', $typeUnverifiable],
                ['Deadlines corrigées' . ($isDryRun ? ' (dry-run — à corriger avec --force)' : ''), $deadlineCorrected],
            ]
        );

        if ($typeUnverifiable > 0) {
            $io->note(
                'Des ressources ont un type non vérifiable faute de ScrapedResource source '
                . 'retrouvée (soumissions manuelles, ou URL non retrouvée). Leur ResourceType '
                . 'n\'a pas été modifié. Voir le docblock de la commande pour les options V2.'
            );
        }

        if ($isDryRun) {
            $io->warning('DRY-RUN : aucune donnée écrite. Relancer avec --force pour appliquer les corrections listées.');
        } else {
            $io->success(sprintf(
                'Rattrapage terminé : %d type(s) corrigé(s), %d deadline(s) corrigée(s), %d non vérifiable(s).',
                $typeCorrected,
                $deadlineCorrected,
                $typeUnverifiable
            ));
        }

        return Command::SUCCESS;
    }
}
