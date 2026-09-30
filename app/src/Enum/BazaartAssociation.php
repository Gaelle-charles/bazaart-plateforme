<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * BazaartAssociation — les deux associations de l'équipe (ADR-0038).
 *
 * Sert à l'onglet « Opportunités » de l'Espace projets : chaque opportunité
 * (aide, bourse, appel à projets, résidence…) est évaluée pour CHACUNE des deux
 * associations, car leurs territoires diffèrent.
 *
 * POURQUOI un enum et pas une table ?
 *   Il n'y a que deux associations, qui changent rarement. Un enum garde les
 *   critères lisibles au même endroit, versionnés avec le code. Si un jour il en
 *   faut une troisième, on ajoute un « case » ici.
 */
enum BazaartAssociation: string
{
    case Guadeloupe = 'guadeloupe';
    case Paris      = 'paris';

    public function label(): string
    {
        return match ($this) {
            self::Guadeloupe => 'BazaArt Guadeloupe',
            self::Paris      => 'BazaArt Paris',
        };
    }

    /** Nom court du territoire, affiché dans les raisons (« Territoire : Guadeloupe »). */
    public function territoryLabel(): string
    {
        return match ($this) {
            self::Guadeloupe => 'Guadeloupe',
            self::Paris      => 'Paris / Île-de-France',
        };
    }

    /**
     * Mots qui désignent le territoire de l'association dans une opportunité.
     *
     * Écrits SANS accents et en minuscules : le texte de l'opportunité est
     * normalisé de la même façon avant comparaison (« Île-de-France » → « ile-de-france »).
     * La recherche se fait sur des MOTS ENTIERS (« paris » ne matche pas « comparaison »).
     *
     * @return list<string>
     */
    public function territoryKeywords(): array
    {
        return match ($this) {
            self::Guadeloupe => [
                'guadeloupe', 'guadeloupeen', 'guadeloupeenne', 'guadeloupeens', 'guadeloupeennes',
                'pointe-a-pitre', 'basse-terre', 'marie-galante', 'les abymes', 'baie-mahault',
                'antilles', 'antillais', 'antillaise', 'caraibe', 'caraibes', 'caribeen', 'caribeenne',
                'outre-mer', 'ultramarin', 'ultramarins', 'ultramarine', 'ultramarines', 'drom', 'dom-tom',
            ],
            self::Paris => [
                'paris', 'parisien', 'parisienne', 'ile-de-france', 'ile de france', 'idf', 'francilien', 'francilienne', 'franciliens',
                'seine-saint-denis', 'hauts-de-seine', 'val-de-marne', 'val-d\'oise', 'essonne', 'yvelines', 'seine-et-marne',
                'grand paris', 'saint-denis', 'montreuil',
            ],
        };
    }
}
