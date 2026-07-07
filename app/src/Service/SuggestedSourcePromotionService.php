<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScrapingSource;
use App\Entity\SuggestedSource;
use App\Enum\ScrapingSourceType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SuggestedSourcePromotionService — Transforme une SuggestedSource en ScrapingSource active.
 *
 * POURQUOI CE SERVICE EXISTE (factorisation ADR-0034) :
 *   Avant ADR-0034, la seule façon de "promouvoir" une suggestion en source active
 *   était AdminSuggestedSourceController::validate() (clic admin manuel). Avec
 *   l'auto-validation RSS (ADR-0034, cf. SuggestedSourceAutoValidationService),
 *   deux appelants ont désormais besoin de la MÊME logique de mapping :
 *     - le controller admin (validation manuelle, tous types RSS/HtmlLlm)
 *     - le service d'auto-validation (validation automatique, RSS confirmé uniquement)
 *   Ce service centralise cette logique pour éviter la duplication (DRY) et garantir
 *   que les deux chemins créent des ScrapingSource strictement équivalentes.
 *
 * CE QUE CE SERVICE NE FAIT PAS :
 *   Il ne modifie JAMAIS le statut de la SuggestedSource passée en paramètre — c'est
 *   la responsabilité de l'appelant (le statut final diffère : Validee pour le
 *   chemin manuel, AutoValidee pour le chemin automatique).
 *   Il ne flush PAS l'EntityManager — l'appelant garde le contrôle de la transaction
 *   (flush unique après la boucle dans app:discover-sources, flush immédiat dans
 *   le controller admin).
 */
class SuggestedSourcePromotionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Crée une ScrapingSource active à partir d'une SuggestedSource et la persiste
     * (sans flush — voir la note de classe ci-dessus).
     *
     * Reprend exactement le mapping historique de AdminSuggestedSourceController::validate() :
     *   - scraperSlug = null            → GenericScraper prend le relais (pas de classe PHP dédiée)
     *   - actif = true                  → activée par défaut, l'admin peut désactiver ensuite
     *   - estAgregateur = false         → une source découverte n'est pas elle-même un agrégateur
     *   - disciplinePrincipale/paysZone → copiés depuis les champs "pressentis" de la suggestion
     *
     * @param SuggestedSource     $suggestion Suggestion source (son URL doit être non vide —
     *                                        vérifié par l'appelant AVANT d'invoquer cette méthode)
     * @param ScrapingSourceType  $type       Type retenu pour la nouvelle source (RSS ou HtmlLlm)
     * @param string|null         $feedUrl    URL du flux RSS/Atom si distincte de l'URL principale
     *                                        (ex : détectée par FeedDetectorService sur une variante
     *                                        comme /feed/ — voir ScrapingSource::$feedUrl). Laisser
     *                                        null si l'URL principale EST déjà le flux, ou si le
     *                                        type n'est pas RSS.
     *
     * @return ScrapingSource La nouvelle source, déjà persist() mais pas encore flush()
     */
    public function createScrapingSourceFromSuggestion(
        SuggestedSource $suggestion,
        ScrapingSourceType $type,
        ?string $feedUrl = null,
    ): ScrapingSource {
        $source = new ScrapingSource();
        $source->setNom($suggestion->getNomOrganisme());
        // getUrl() est ?string sur SuggestedSource — l'appelant garantit la non-nullité
        // (voir AdminSuggestedSourceController::validate() et
        // SuggestedSourceAutoValidationService::tryAutoValidate() qui vérifient empty($url) avant).
        $source->setUrl((string) $suggestion->getUrl());
        $source->setType($type);
        $source->setScraperSlug(null);
        $source->setDisciplinePrincipale($suggestion->getDisciplinePressentie());
        $source->setPaysZone($suggestion->getPaysZone());
        $source->setActif(true);
        $source->setEstAgregateur(false);

        // Champ feedUrl (WS1) : si FeedDetectorService a trouvé le flux sur une URL
        // différente de l'URL principale (ex : suffixe /feed/), on la renseigne pour
        // que FeedReaderService cible directement le bon flux plutôt que de retomber
        // sur l'URL principale (comportement de FeedReaderService::getFeedUrl() : priorité
        // à feedUrl, fallback sur url si feedUrl est null).
        if ($feedUrl !== null) {
            $source->setFeedUrl($feedUrl);
        }

        $this->em->persist($source);

        return $source;
    }
}
