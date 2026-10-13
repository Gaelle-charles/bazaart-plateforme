<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0039 : les 9 fiches de départ de la page « Projets en cours ».
 *
 * Seuls le titre, le slug, le type, l'état et l'ordre sont remplis : l'équipe complète
 * le reste dans le back-office (/admin/fiches-projets). TOUTES les fiches sont créées
 * NON PUBLIÉES : rien n'apparaît côté partenaires tant que l'équipe ne coche pas « Publié ».
 *
 * POURQUOI UNE MIGRATION (et pas des fixtures) ?
 *   Les fixtures ne sont installées qu'en développement (composer install --no-dev
 *   en production) : seule une migration crée ces lignes sur app.bazaart.fr.
 *
 * IDEMPOTENCE (convention du projet pour les migrations de données) :
 *   `ON CONFLICT (slug) DO NOTHING` : rejouer la migration ne crée pas de doublon
 *   et n'écrase jamais une fiche déjà complétée par l'équipe.
 *
 * SLUGS ÉCRITS EN DUR : ils correspondent à ce que produit le générateur de slug
 * du back-office (accents retirés, minuscules, tirets). On ne l'appelle pas ici car
 * une migration doit donner le même résultat quel que soit le code futur.
 *
 * VALEURS DES TYPES / ÉTATS : ce sont les valeurs des enums AssociationProjectType
 * et AssociationProjectState (ex. 'funding' = « Financement en cours »).
 *
 * ORDRE : 10, 20… 90 (pas de 10) pour pouvoir intercaler une fiche (ex. 15) sans
 * tout renuméroter.
 */
final class Version20261013020000 extends AbstractMigration
{
    /**
     * Fiches de départ : [ordre, titre, slug, type, état].
     *
     * @var list<array{int, string, string, string, string}>
     */
    private const array SEED = [
        [10, 'Zagaza · Maison Verveau', 'zagaza-maison-verveau', 'residency', 'funding'],
        [20, "Coopération Guadeloupe-Côte d'Ivoire", 'cooperation-guadeloupe-cote-d-ivoire', 'cooperation', 'in_progress'],
        [30, 'An Ba Lanmè, chapitre 2', 'an-ba-lanme-chapitre-2', 'residency', 'funding'],
        [40, 'masonn · Bolivie', 'masonn-bolivie', 'residency', 'in_progress'],
        [50, 'Formations', 'formations', 'training', 'in_progress'],
        [60, 'Plateforme app.bazaart.fr', 'plateforme-app-bazaart-fr', 'platform_media', 'in_progress'],
        [70, 'Média BazaArt', 'media-bazaart', 'platform_media', 'in_progress'],
        [80, 'Groupes de travail ICC Guadeloupe', 'groupes-de-travail-icc-guadeloupe', 'cooperation', 'in_progress'],
        [90, 'Tiers-lieu, phase 1 : équipement', 'tiers-lieu-phase-1-equipement', 'structuring', 'funding'],
    ];

    public function getDescription(): string
    {
        return 'ADR-0039 : 9 fiches de départ de la page Projets en cours (toutes non publiées).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SEED as [$position, $title, $slug, $type, $state]) {
            // Requête PARAMÉTRÉE (les « ? ») : les apostrophes des titres
            // (ex. « Côte d'Ivoire ») ne peuvent pas casser le SQL.
            $this->addSql(
                'INSERT INTO association_projects (title, slug, type, state, position, is_published, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, false, NOW(), NOW())
                 ON CONFLICT (slug) DO NOTHING',
                [$title, $slug, $type, $state, $position],
            );
        }
    }

    /**
     * Retour arrière PRUDENT : on ne supprime une fiche de départ que si l'équipe
     * ne l'a jamais touchée (jamais modifiée, jamais publiée, aucun bloc ajouté).
     * Une fiche complétée entre-temps est conservée.
     */
    public function down(Schema $schema): void
    {
        $slugs = array_map(static fn (array $row): string => $row[2], self::SEED);

        $this->addSql(
            'DELETE FROM association_projects p
             WHERE p.slug IN (' . implode(', ', array_fill(0, count($slugs), '?')) . ')
               AND p.is_published = false
               AND p.updated_at = p.created_at
               AND NOT EXISTS (SELECT 1 FROM association_project_blocks b WHERE b.project_id = p.id)',
            $slugs,
        );
    }
}
