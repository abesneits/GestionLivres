<?php
/**
 * Garde-fous sur les chemins de fichiers enregistrés en base (couvertures, photos, ebooks).
 *
 * Ces chemins sont relatifs (« uploads/... ») et viennent de la base de données, donc aussi
 * d'une sauvegarde JSON restaurée : avant de lire, supprimer ou servir un fichier à partir
 * d'un tel chemin, on vérifie qu'il désigne bien un fichier ordinaire situé dans uploads/.
 */
class Fichiers {
    /**
     * Forme attendue d'un chemin local : uploads/[sous-dossiers/]nom.ext, sans « .. » ni caractère inattendu.
     */
    const MOTIF_CHEMIN_UPLOAD = '#^uploads/([A-Za-z0-9_\-]+/)*[A-Za-z0-9_\-]+\.[A-Za-z0-9]+$#';

    /**
     * Le texte a-t-il la forme d'un chemin de fichier envoyé par l'utilisateur ? (le fichier peut ne pas exister)
     */
    public static function estCheminUpload($chemin) {
        return is_string($chemin) && preg_match(self::MOTIF_CHEMIN_UPLOAD, $chemin) === 1;
    }

    /**
     * Chemin réel du fichier s'il existe et se trouve bien dans uploads/ (hors fichiers cachés
     * comme .htaccess), sinon null. Les liens symboliques et « .. » sont résolus avant la comparaison.
     */
    public static function cheminSur($chemin) {
        if (!is_string($chemin) || $chemin === '') {
            return null;
        }
        $base = realpath(__DIR__ . '/../uploads');
        $reel = @realpath($chemin);
        if ($base === false || $reel === false || !is_file($reel)) {
            return null;
        }
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $reel = str_replace('\\', '/', $reel);
        if (strncmp($reel, $base, strlen($base)) !== 0 || basename($reel)[0] === '.') {
            return null;
        }
        return $reel;
    }

    /**
     * Supprimer un fichier d'uploads/. Retourne false (sans rien supprimer) si le chemin est hors de uploads/.
     */
    public static function supprimer($chemin) {
        $reel = self::cheminSur($chemin);
        return $reel !== null && @unlink($reel);
    }
}
