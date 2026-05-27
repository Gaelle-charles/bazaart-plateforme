<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScrapingSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ScrapingSourceRepository — Requêtes BDD pour les sources de scraping.
 *
 * Ce repository centralise toutes les requêtes Doctrine sur la table scraping_sources.
 * Conformément à CLAUDE.md §4, la logique de requête doit rester dans les repositories,
 * jamais dans les services ni les controllers.
 *
 * @extends ServiceEntityRepository<ScrapingSource>
 */
class ScrapingSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScrapingSource::class);
    }

    /**
     * Retourne toutes les sources actives, triées par nom.
     *
     * Utilisé par ScrapeOpportunitiesCommand pour savoir quoi scraper.
     * Seules les sources avec actif = true sont prises en compte.
     *
     * @return ScrapingSource[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('s.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne toutes les sources (actives + inactives), triées par nom.
     *
     * Utilisé par la page admin /admin/scraping-sources pour afficher la liste complète.
     *
     * @return ScrapingSource[]
     */
    public function findAllOrderedByNom(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retrouve une source par son scraperSlug.
     *
     * Utilisé lors du seed (SeedScrapingSourcesCommand) pour vérifier
     * si un slug existe déjà avant d'en créer un nouveau.
     *
     * @param string $slug Slug PHP de la classe (ex: "cnap", "on-the-move")
     */
    public function findBySlug(string $slug): ?ScrapingSource
    {
        return $this->findOneBy(['scraperSlug' => $slug]);
    }

    /**
     * Retrouve une source par son URL exacte.
     *
     * Utilisé pour la déduplication lors du seed et dans le formulaire admin
     * (empêche d'ajouter deux fois la même URL).
     *
     * @param string $url URL complète de la source
     */
    public function findByUrl(string $url): ?ScrapingSource
    {
        return $this->findOneBy(['url' => $url]);
    }

    /**
     * Retourne les sources actives marquées comme agrégateurs.
     *
     * Utilisé exclusivement par app:discover-sources pour cibler les pages
     * à analyser afin de trouver de nouvelles sources culturelles.
     *
     * Double condition :
     *   - actif = true       → les sources désactivées par l'admin sont ignorées
     *   - estAgregateur = true → seuls les sites qui listent d'autres organismes sont ciblés
     *
     * Retour trié par nom pour un affichage cohérent dans les logs de la commande.
     *
     * @return ScrapingSource[]
     */
    public function findActiveAggregators(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.actif = true')
            ->andWhere('s.estAgregateur = true')
            ->orderBy('s.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
