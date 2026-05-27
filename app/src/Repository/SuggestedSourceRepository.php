<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SuggestedSource;
use App\Enum\SuggestedSourceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SuggestedSourceRepository — Requêtes BDD pour les sources suggérées.
 *
 * Ce repository centralise toutes les requêtes Doctrine sur la table suggested_sources.
 * Conformément à CLAUDE.md §4, la logique de requête doit rester dans les repositories,
 * jamais dans les services ni les controllers.
 *
 * Méthodes clés :
 *   - findAllByStatut()     → grouper les suggestions par statut (admin)
 *   - findPending()         → alias pratique pour les suggestions "À valider"
 *   - countByStatut()       → compter pour les badges (sidebar, header)
 *   - existsByUrl()         → déduplication avant création d'une nouvelle suggestion
 *   - findAllOrderedByDate() → vue chronologique inverse (plus récentes en premier)
 *
 * @extends ServiceEntityRepository<SuggestedSource>
 */
class SuggestedSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuggestedSource::class);
    }

    /**
     * Retourne toutes les suggestions ayant un statut donné.
     *
     * Utilisé par AdminSuggestedSourceController pour grouper les suggestions
     * par section dans le template (À valider, Validées, Rejetées).
     * Triées par dateDecouverte DESC — les plus récentes apparaissent en premier.
     *
     * @param SuggestedSourceStatus $statut Le statut à filtrer
     * @return SuggestedSource[]
     */
    public function findAllByStatut(SuggestedSourceStatus $statut): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.statut = :statut')
            ->setParameter('statut', $statut->value)
            ->orderBy('s.dateDecouverte', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne toutes les suggestions en attente de validation.
     *
     * Alias pratique de findAllByStatut(AValider) pour rendre le code appelant
     * plus lisible (sémantique claire sans avoir à importer l'enum).
     *
     * @return SuggestedSource[]
     */
    public function findPending(): array
    {
        return $this->findAllByStatut(SuggestedSourceStatus::AValider);
    }

    /**
     * Retourne toutes les suggestions, triées par dateDecouverte décroissante.
     *
     * Vue complète (tous statuts confondus) pour un historique global.
     * Utilisable pour un futur export CSV ou une vue globale admin.
     *
     * @return SuggestedSource[]
     */
    public function findAllOrderedByDate(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.dateDecouverte', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre de suggestions ayant un statut donné.
     *
     * Utilisé pour les badges numériques dans la sidebar admin
     * et dans le header de la page /admin/suggested-sources.
     *
     * Exemple :
     *   $pendingCount = $repo->countByStatut(SuggestedSourceStatus::AValider);
     *   // → affiche le badge "12 à valider" dans la sidebar
     *
     * @param SuggestedSourceStatus $statut Le statut à compter
     * @return int Nombre de suggestions avec ce statut
     */
    public function countByStatut(SuggestedSourceStatus $statut): int
    {
        // COUNT retourne une valeur entière — cast explicite pour la lisibilité et PHPStan
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.statut = :statut')
            ->setParameter('statut', $statut->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifie si une URL est déjà enregistrée dans les suggestions.
     *
     * RÈGLE DE DÉDUPLICATION : app:discover-sources appelle cette méthode
     * avant toute création de SuggestedSource pour éviter de re-soumettre
     * la même URL, peu importe son statut courant (AValider, Validee, Rejetee).
     *
     * Pourquoi vérifier même les rejetées ?
     *   Si l'admin a rejeté un organisme, c'est une décision intentionnelle.
     *   On ne doit pas le re-suggérer au run suivant, même si le LLM le retrouve.
     *
     * @param string $url URL à vérifier (comparaison exacte)
     * @return bool true si l'URL est déjà dans la table, false sinon
     */
    public function existsByUrl(string $url): bool
    {
        // COUNT > 0 plutôt que findOneBy() pour éviter de charger l'entité entière
        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.url = :url')
            ->setParameter('url', $url)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
