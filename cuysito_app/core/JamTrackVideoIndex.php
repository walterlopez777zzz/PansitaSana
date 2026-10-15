<?php
/**
 * ============================================================================
 * BANANO STORE — JAM TRACK VIDEO INDEX
 * ============================================================================
 * Answers "does this Jam Track have playable media?" for a whole shop
 * payload with ONE query, so the storefront can render the video badge on
 * every card at first paint without a per-card request.
 *
 * Single source of truth: `pistas_musicales.youtube_id` OR a direct media
 * file (`media_file`, admin-uploaded audio/video). Every publishing path
 * (admin panel, community submissions, system imports) writes there, and the
 * item page (get_video.php → vc_track_state) reads the very same columns
 * keyed by `nombre_pista` = tracks[0].title, so the badge and the player can
 * never disagree. Databases that predate `media_file` fall back to the
 * YouTube-only predicate.
 *
 * Output contract (consumed by app.js): `tracks[i].hasVideo` boolean on every
 * track of every entry. Missing table / DB failure → all `false`; the shop
 * itself is never affected.
 * ============================================================================
 */

if (!class_exists('JamTrackVideoIndex')) {

final class JamTrackVideoIndex
{
    /**
     * Loads the set of track names that currently have a video.
     *
     * Keys are normalized with {@see self::key()} so PHP matching mirrors the
     * case-insensitive collation MySQL applies to `nombre_pista = ?`.
     *
     * @return array<string,true>
     */
    public static function load(mysqli $conn): array
    {
        $index = [];
        try {
            $result = false;
            try {
                $result = @$conn->query(
                    "SELECT nombre_pista FROM pistas_musicales
                     WHERE (youtube_id IS NOT NULL AND youtube_id <> '')
                        OR (media_file IS NOT NULL AND media_file <> '')"
                );
            } catch (Throwable $e) {
                $result = false;
            }
            if (!$result) {
                $result = $conn->query(
                    "SELECT nombre_pista FROM pistas_musicales
                     WHERE youtube_id IS NOT NULL AND youtube_id <> ''"
                );
            }
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $key = self::key((string) ($row['nombre_pista'] ?? ''));
                    if ($key !== '') $index[$key] = true;
                }
                $result->free();
            }
        } catch (Throwable $e) {
            return [];
        }
        return $index;
    }

    /**
     * Flags `hasVideo` on each track of a canonical shop entry (ShopNormalizer
     * shape). Entries without tracks are returned untouched.
     *
     * @param  array<string,mixed> $entry
     * @param  array<string,true>  $index Result of {@see self::load()}.
     * @return array<string,mixed>
     */
    public static function annotate(array $entry, array $index): array
    {
        if (!is_array($entry['tracks'] ?? null)) return $entry;

        foreach ($entry['tracks'] as $i => $track) {
            if (!is_array($track)) continue;
            $key = self::key((string) ($track['title'] ?? ''));
            $entry['tracks'][$i]['hasVideo'] = $key !== '' && isset($index[$key]);
        }
        return $entry;
    }

    private static function key(string $trackName): string
    {
        return mb_strtolower(trim($trackName), 'UTF-8');
    }
}

}
