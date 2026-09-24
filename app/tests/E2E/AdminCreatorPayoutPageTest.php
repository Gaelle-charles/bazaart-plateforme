<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\CreatorPayoutProfile;
use App\Enum\CreatorVerificationStatus;

/**
 * AdminCreatorPayoutPageTest — la page admin « Versements » s'affiche (ADR-0027).
 *
 * Régression : le template utilisait un filtre Twig `truncate` qui n'existe pas
 * dans ce projet (il venait de l'ancien paquet Twig Extensions, non installé).
 * Twig compile le template entier : la page plantait (erreur 500) pour TOUS les
 * admins, même sans dossier refusé.
 */
class AdminCreatorPayoutPageTest extends AbstractE2ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDatabase();
    }

    public function testPayoutsPageRendersForAdmin(): void
    {
        $this->loginAs($this->createAdminUser());
        $this->client->request('GET', '/admin/versements');

        $this->assertResponseIsSuccessful();
    }

    public function testLongRejectionReasonIsShortened(): void
    {
        $admin   = $this->createAdminUser();
        $creator = $this->createArtistUser('createur@test.fr');

        $profile = (new CreatorPayoutProfile())
            ->setUser($creator)
            ->setIban('FR7630006000011234567890189')
            ->setSiret('12345678901234')
            ->setAccountHolderName('Créatrice Test')
            ->setStatus(CreatorVerificationStatus::Rejected)
            ->setRejectionReason(str_repeat('Pièce illisible. ', 10) . 'FIN-DU-MOTIF');
        $this->em->persist($profile);
        $this->em->flush();

        $this->loginAs($admin);
        $crawler = $this->client->request('GET', '/admin/versements');

        $this->assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Motif : Pièce illisible.', $text);
        self::assertStringNotContainsString('FIN-DU-MOTIF', $text, 'Le motif est coupé à 80 caractères');
    }
}
