<?php

require_once __DIR__ . '/EntityRepository.php';
require_once __DIR__ . '/Offer.php';
require_once __DIR__ . '/Landing.php';

/**
 * Expands a flow step's first-class offer/landing references (Phase 1.5) into
 * the step's existing folderNames / redirectUrls structures, so the routing
 * engine continues to operate on the same primitives it always has.
 *
 * Resolution rules (additive — explicit folders/URLs are preserved):
 *   - Offers              -> redirect URL entries (label = offer name).
 *   - Remote landings     -> redirect URL entries (label = landing name).
 *   - Local landings      -> folder names (the landing's folder).
 *
 * The step is mutated in place. Missing or invalid ids are skipped silently so
 * a deleted entity never breaks routing.
 */
class FlowEntityResolver
{
    public static function expandStep(object $step, EntityRepository $offers, EntityRepository $landings): void
    {
        if (!method_exists($step, 'hasEntityRefs') || !$step->hasEntityRefs()) {
            return;
        }

        foreach ($step->offerIds as $offerId) {
            $offer = $offers->find((int)$offerId);
            if (!$offer instanceof Offer) {
                continue;
            }
            $url = $offer->url();
            if ($url !== '') {
                self::addRedirect($step, $url, $offer->name);
            }
        }

        foreach ($step->landingIds as $landingId) {
            $landing = $landings->find((int)$landingId);
            if (!$landing instanceof Landing) {
                continue;
            }
            if ($landing->isRemote()) {
                if ($landing->url() !== '') {
                    self::addRedirect($step, $landing->url(), $landing->name);
                }
            } elseif ($landing->path() !== '' && !in_array($landing->path(), $step->folderNames, true)) {
                $step->folderNames[] = $landing->path();
            }
        }
    }

    private static function addRedirect(object $step, string $url, string $label): void
    {
        foreach ($step->redirectUrls as $r) {
            if (($r['url'] ?? '') === $url) {
                return;
            }
        }
        $step->redirectUrls[] = ['url' => $url, 'label' => $label !== '' ? $label : $url];
    }
}
