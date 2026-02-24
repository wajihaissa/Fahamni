<?php

namespace App\Service;

class SpellCheckerService
{
    private array $expressions;
    private array $typoCorrections;
    private array $accentDictionary;
    private array $safeWords;

    public function __construct()
    {
        // safeWords DOIT être initialisé en premier (utilisé par buildAccentDictionary)
        $this->safeWords = [
            'la', 'le', 'les', 'du', 'des', 'de', 'un', 'une',
            'au', 'aux', 'sur', 'sous', 'par', 'pour', 'en', 'ou',
            'et', 'est', 'son', 'sa', 'ses', 'mon', 'ma', 'mes',
            'ton', 'ta', 'tes', 'ce', 'se', 'ne', 'je', 'tu',
            'il', 'on', 'nous', 'vous', 'ils', 'elles',
            'age', 'ages', 'mur', 'murs', 'cote', 'cotes',
            'tot', 'tache', 'taches', 'grace', 'pole',
            'hotel', 'hopital', 'ile', 'ete', 'pret', 'tete',
            'fete', 'foret', 'bete', 'reve', 'pate', 'hate',
            'role', 'diplome', 'cable', 'chateau',
            'a', 'y', 'ou', 'ni', 'si', 'que', 'qui', 'dont',
        ];
        $this->expressions = $this->buildExpressions();
        $this->typoCorrections = $this->buildTypoCorrections();
        $this->accentDictionary = $this->buildAccentDictionary();
    }

    public function correct(string $text): array
    {
        $original = $text;
        $corrected = $text;
        $fixes = [];

        // 1. Expressions multi-mots (AVANT les mots individuels)
        foreach ($this->expressions as $wrong => $right) {
            $pattern = '/' . preg_quote($wrong, '/') . '/iu';
            if (preg_match($pattern, $corrected)) {
                $corrected = preg_replace($pattern, $right, $corrected);
                $fixes[] = $wrong . ' → ' . $right;
            }
        }

        // 2. Typos (lettres inversées, manquantes, doublées)
        foreach ($this->typoCorrections as $wrong => $right) {
            $pattern = '/(?<=\s|^|\')' . preg_quote($wrong, '/') . '(?=\s|$|[.,;:!?\'])/iu';
            if (preg_match($pattern, $corrected)) {
                $corrected = preg_replace($pattern, $right, $corrected);
                $fixes[] = $wrong . ' → ' . $right;
            }
        }

        // 3. Accents manquants (détection automatique)
        $corrected = $this->fixMissingAccents($corrected, $fixes);

        // 4. Similarité Levenshtein (typos restants)
        $corrected = $this->fixBySimilarity($corrected, $fixes);

        // 5. Majuscule en début de phrase
        $corrected = preg_replace_callback('/(?:^|[.!?]\s+)(\p{Ll})/u', function ($m) {
            return str_replace($m[1], mb_strtoupper($m[1]), $m[0]);
        }, $corrected);

        // 6. Point final si absent
        $trimmed = rtrim($corrected);
        if ($trimmed !== '' && !preg_match('/[.!?]$/', $trimmed)) {
            $corrected = $trimmed . '.';
            $fixes[] = 'Point final ajouté';
        }

        // 7. Espaces multiples
        $before = $corrected;
        $corrected = preg_replace('/  +/', ' ', $corrected);
        if ($before !== $corrected) {
            $fixes[] = 'Espaces multiples corrigés';
        }

        // 8. Espace avant ponctuation double FR
        $before = $corrected;
        $corrected = preg_replace('/\s*([;:!?])/', ' $1', $corrected);
        $corrected = preg_replace('/([(\[«])\s*([;:!?])/', '$1$2', $corrected);
        if ($before !== $corrected) {
            $fixes[] = 'Espacement ponctuation corrigé';
        }

        // 9. Pas d'espace avant , et .
        $before = $corrected;
        $corrected = preg_replace('/\s+([,\.])/', '$1', $corrected);
        if ($before !== $corrected) {
            $fixes[] = 'Espace avant virgule/point supprimé';
        }

        return [
            'corrected' => $corrected,
            'original' => $original,
            'fixes' => $fixes,
            'fixCount' => count($fixes),
            'hasChanges' => $original !== $corrected,
        ];
    }

    /**
     * Détecte les mots sans accents et les corrige.
     * Ne touche PAS aux mots courts ambigus (la, du, sur, etc.)
     */
    private function fixMissingAccents(string $text, array &$fixes): string
    {
        return preg_replace_callback('/(?<=[\s\',;:!?.()\[\]]|^)([a-zA-ZÀ-ÿ]+)(?=[\s\',;:!?.()\[\]]|$)/u', function ($match) use (&$fixes) {
            $word = $match[1];
            $lower = mb_strtolower($word);

            // Ne pas corriger les mots courts ambigus
            if (in_array($lower, $this->safeWords)) {
                return $word;
            }

            // Minimum 3 lettres pour la correction d'accents
            if (mb_strlen($lower) < 3) {
                return $word;
            }

            $stripped = $this->stripAccents($lower);

            if (isset($this->accentDictionary[$stripped])) {
                $correctWord = $this->accentDictionary[$stripped];

                // Corriger seulement si le mot diffère
                if ($lower !== $correctWord) {
                    $result = $this->preserveCase($word, $correctWord);
                    $fixes[] = $word . ' → ' . $result;
                    return $result;
                }
            }

            return $word;
        }, $text);
    }

    /**
     * Détecte les typos par distance de Levenshtein.
     * Utilise stripAccents pour comparer correctement en UTF-8.
     */
    private function fixBySimilarity(string $text, array &$fixes): string
    {
        $referenceWords = $this->getCommonFrenchWords();

        // Préparer un index stripped pour les mots de référence
        $strippedRef = [];
        foreach ($referenceWords as $ref) {
            $strippedRef[$ref] = $this->stripAccents($ref);
        }

        // Mots minimum 6 lettres pour Levenshtein (évite les faux positifs sur mots courts)
        return preg_replace_callback('/(?<=[\s\',;:!?.()\[\]]|^)([a-zA-ZÀ-ÿ]{6,})(?=[\s\',;:!?.()\[\]]|$)/u', function ($match) use (&$fixes, $referenceWords, $strippedRef) {
            $word = $match[1];
            $lower = mb_strtolower($word);
            $strippedWord = $this->stripAccents($lower);

            // Skip si déjà connu dans le dictionnaire d'accents
            if (isset($this->accentDictionary[$strippedWord])) {
                return $word;
            }

            // Skip si c'est un mot de référence connu
            if (in_array($lower, $referenceWords) || in_array($strippedWord, $strippedRef)) {
                return $word;
            }

            // Seuil adaptatif: distance 1 pour mots 6-7 lettres, distance 2 pour 8+
            $maxDistance = mb_strlen($strippedWord) >= 8 ? 2 : 1;

            $bestMatch = null;
            $bestDistance = $maxDistance + 1;

            foreach ($referenceWords as $ref) {
                $strippedRefWord = $strippedRef[$ref];

                if (abs(strlen($strippedRefWord) - strlen($strippedWord)) > $maxDistance) {
                    continue;
                }

                $distance = levenshtein($strippedWord, $strippedRefWord);
                if ($distance > 0 && $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestMatch = $ref;
                }
            }

            if ($bestMatch !== null && $bestDistance <= $maxDistance) {
                $result = $this->preserveCase($word, $bestMatch);
                $fixes[] = $word . ' → ' . $result;
                return $result;
            }

            return $word;
        }, $text);
    }

    /**
     * Supprime tous les accents d'un mot (pour comparaison).
     */
    private function stripAccents(string $str): string
    {
        $accents = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'é' => 'e',
            'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ý' => 'y',
            'ñ' => 'n',
            'ç' => 'c',
            'œ' => 'oe', 'æ' => 'ae',
        ];

        return strtr($str, $accents);
    }

    /**
     * Préserve la casse du mot original sur le mot corrigé.
     */
    private function preserveCase(string $original, string $replacement): string
    {
        // Tout en majuscules
        if (mb_strtoupper($original) === $original && mb_strlen($original) > 1) {
            return mb_strtoupper($replacement);
        }

        // Première lettre majuscule (compatible UTF-8)
        $firstChar = mb_substr($original, 0, 1);
        if (mb_strtoupper($firstChar) === $firstChar && mb_strtolower($firstChar) !== $firstChar) {
            return mb_strtoupper(mb_substr($replacement, 0, 1)) . mb_substr($replacement, 1);
        }

        return $replacement;
    }

    // =========================================================================
    //  DICTIONNAIRES
    // =========================================================================

    private function buildExpressions(): array
    {
        return [
            // Expressions académiques (les plus longues d'abord)
            "projet de fine d'etude" => "projet de fin d'étude",
            "projet de fines d'etudes" => "projet de fin d'études",
            "projet de fin d'etude" => "projet de fin d'étude",
            "projet de fin d'etudes" => "projet de fin d'études",
            "projet fine d'etudes" => "projet fin d'études",
            "projet fines d'etudes" => "projet fin d'études",
            "projet fin d'etudes" => "projet fin d'études",
            "fine d'etudes" => "fin d'études",
            "fines d'etudes" => "fin d'études",
            "fine d'études" => "fin d'études",
            "fines d'études" => "fin d'études",
            "fin d'etudes" => "fin d'études",
            "fin d'etude" => "fin d'étude",
            // Expressions courantes
            "mise en oeuvre" => "mise en œuvre",
            "chef d'oeuvre" => "chef-d'œuvre",
            "peut etre" => "peut-être",
            "c'est a dire" => "c'est-à-dire",
            "c est a dire" => "c'est-à-dire",
            "vis a vis" => "vis-à-vis",
            "en terme de" => "en termes de",
            "au jour d'aujourd'hui" => "aujourd'hui",
            "data base" => "database",
            // Locutions prépositives avec "à"
            'a partir de' => 'à partir de',
            'a travers' => 'à travers',
            'tout a fait' => 'tout à fait',
            'a fin de' => 'afin de',
            'a fin que' => 'afin que',
            'grace a' => 'grâce à',
            'quant a' => 'quant à',
            'par rapport a' => 'par rapport à',
            'au dela de' => 'au-delà de',
            'au dela' => 'au-delà',
            'jusqu a' => "jusqu'à",
            'des a present' => 'dès à présent',
            'des lors' => 'dès lors',
            'a cause de' => 'à cause de',
            'a condition de' => 'à condition de',
            // Bases de données / IA
            'base de donnee' => 'base de données',
            'base de donnees' => 'base de données',
            'bases de donnee' => 'bases de données',
            'bases de donnees' => 'bases de données',
            'intelligence articielle' => 'intelligence artificielle',
            'inteligence artificielle' => 'intelligence artificielle',
            'inteligence articielle' => 'intelligence artificielle',
            'machine learnnig' => 'machine learning',
            'deep learnnig' => 'deep learning',
            'open sorce' => 'open source',
            // Autres expressions académiques
            'entre autre' => 'entre autres',
            'de ce fait' => 'de ce fait',
            'au fur et a mesure' => 'au fur et à mesure',
            'en ce qui concerne' => 'en ce qui concerne',
            'point de vue' => 'point de vue',
        ];
    }

    private function buildTypoCorrections(): array
    {
        return [
            'acompagner' => 'accompagner',
            'acomplir' => 'accomplir',
            'algorythme' => 'algorithme',
            'algoritheme' => 'algorithme',
            'aplication' => 'application',
            'aprendre' => 'apprendre',
            'apremdre' => 'apprendre',
            'aprentissage' => 'apprentissage',
            'apprentisage' => 'apprentissage',
            'aquisition' => 'acquisition',
            'artcile' => 'article',
            'articel' => 'article',
            'automatiqement' => 'automatiquement',
            'beaucop' => 'beaucoup',
            'beaucoups' => 'beaucoup',
            'beuacoup' => 'beaucoup',
            'biblioteque' => 'bibliothèque',
            'calulateur' => 'calculateur',
            'comencer' => 'commencer',
            'competance' => 'compétence',
            'comunication' => 'communication',
            'comunaute' => 'communauté',
            'conaissance' => 'connaissance',
            'connaisance' => 'connaissance',
            'conaissances' => 'connaissances',
            'conexion' => 'connexion',
            'connextion' => 'connexion',
            'controlleur' => 'contrôleur',
            'deffinition' => 'définition',
            'developement' => 'développement',
            'diferent' => 'différent',
            'dificile' => 'difficile',
            'dimmension' => 'dimension',
            'donee' => 'donnée',
            'efficase' => 'efficace',
            'egallement' => 'également',
            'environement' => 'environnement',
            'erruer' => 'erreur',
            'exmple' => 'exemple',
            'exellent' => 'excellent',
            'focntion' => 'fonction',
            'fonctionalite' => 'fonctionnalité',
            'framwork' => 'framework',
            'francias' => 'français',
            'genaral' => 'général',
            'generallement' => 'généralement',
            'importent' => 'important',
            'indispensabe' => 'indispensable',
            'informatque' => 'informatique',
            'ingeneur' => 'ingénieur',
            'instalation' => 'installation',
            'inteligent' => 'intelligent',
            'interressant' => 'intéressant',
            'langugage' => 'langage',
            'longeur' => 'longueur',
            'maintenace' => 'maintenance',
            'mantenir' => 'maintenir',
            'meileur' => 'meilleur',
            'neccesaire' => 'nécessaire',
            'nessecaire' => 'nécessaire',
            'normallement' => 'normalement',
            'notament' => 'notamment',
            'objetif' => 'objectif',
            'oganisation' => 'organisation',
            'paramettre' => 'paramètre',
            'performace' => 'performance',
            'permetrre' => 'permettre',
            'permetre' => 'permettre',
            'plateform' => 'plateforme',
            'platefome' => 'plateforme',
            'plusieur' => 'plusieurs',
            'plusieures' => 'plusieurs',
            'pratiqe' => 'pratique',
            'probeme' => 'problème',
            'procedurre' => 'procédure',
            'programation' => 'programmation',
            'puissnt' => 'puissant',
            'rapidment' => 'rapidement',
            'seullement' => 'seulement',
            'sinificatif' => 'significatif',
            'suceptible' => 'susceptible',
            'technnique' => 'technique',
            'tecnologie' => 'technologie',
            'utilisatuer' => 'utilisateur',
            'vraiement' => 'vraiment',
            'symfony' => 'Symfony',
            // Termes techniques supplémentaires
            'analitique' => 'analytique',
            'archtecture' => 'architecture',
            'architechture' => 'architecture',
            'autentification' => 'authentification',
            'authentifcation' => 'authentification',
            'analise' => 'analyse',
            'annalyse' => 'analyse',
            'comparaisson' => 'comparaison',
            'concepttion' => 'conception',
            'configuartion' => 'configuration',
            'configuraton' => 'configuration',
            'conection' => 'connexion',
            'contennu' => 'contenu',
            'documentaion' => 'documentation',
            'documantation' => 'documentation',
            'fonctionel' => 'fonctionnel',
            'identifant' => 'identifiant',
            'implementaion' => 'implémentation',
            'implementaton' => 'implémentation',
            'infomation' => 'information',
            'inforamtion' => 'information',
            'interfce' => 'interface',
            'inteface' => 'interface',
            'introdution' => 'introduction',
            'methodologie' => 'méthodologie',
            'methodoligie' => 'méthodologie',
            'modifcation' => 'modification',
            'modifiaction' => 'modification',
            'occurence' => 'occurrence',
            'occurences' => 'occurrences',
            'persitance' => 'persistance',
            'procesus' => 'processus',
            'professionel' => 'professionnel',
            'professionnell' => 'professionnel',
            'simulaiton' => 'simulation',
            'simluation' => 'simulation',
            'structuer' => 'structure',
            'structurre' => 'structure',
            'utlisateur' => 'utilisateur',
            'utilisaeur' => 'utilisateur',
            'apliquer' => 'appliquer',
            'aappliquer' => 'appliquer',
            'validaion' => 'validation',
            'validaton' => 'validation',
            'varaible' => 'variable',
            'varialbe' => 'variable',
            'commantaire' => 'commentaire',
            'comentaire' => 'commentaire',
            'commentaires' => 'commentaires',
            'supprimer' => 'supprimer',
            'formulaier' => 'formulaire',
            'formulaaire' => 'formulaire',
            'servuer' => 'serveur',
            'srerveur' => 'serveur',
            'logicel' => 'logiciel',
            'logiceil' => 'logiciel',
            'securisation' => 'sécurisation',
            'securiser' => 'sécuriser',
            'gesiton' => 'gestion',
            'gesion' => 'gestion',
            'relationel' => 'relationnel',
            'relatonel' => 'relationnel',
            'modlisation' => 'modélisation',
            'modelisation' => 'modélisation',
            'telcharger' => 'télécharger',
            'telecharger' => 'télécharger',
        ];
    }

    /**
     * Dictionnaire d'accents : mot_sans_accent → mot_correct.
     * Seuls les mots de 3+ lettres et NON ambigus sont inclus.
     */
    private function buildAccentDictionary(): array
    {
        $accentedWords = [
            // === É (début de mot) ===
            'échange', 'échec', 'école', 'économie', 'économique', 'écran', 'écrit',
            'écrire', 'écriture', 'éducation', 'éducatif', 'éducative',
            'également', 'égal', 'égalité', 'élaborer', 'élection', 'électricité',
            'électrique', 'électronique', 'élément', 'éléments', 'élémentaire',
            'élève', 'élèves', 'élevé', 'éliminer', 'éloigner',
            'émission', 'émotion', 'énergie', 'énergétique', 'énorme', 'énormément',
            'enseigné', 'époque', 'épreuve', 'équation', 'équilibre',
            'équipe', 'équipement', 'équivalent',
            'espérer', 'état', 'établir', 'établissement',
            'étage', 'étape', 'étapes',
            'étendue', 'éternel', 'éthique',
            'étiquette', 'étoile', 'étonner', 'étranger', 'étrangère',
            'être', 'étroit', 'étude', 'études', 'étudiant', 'étudiants',
            'étudiante', 'étudiantes', 'étudier',
            'évaluer', 'évaluation', 'événement', 'événements', 'éventuel',
            'éventuellement', 'évidemment', 'évident', 'éviter', 'évolution', 'évoluer',
            'exécuter', 'exécution', 'expérience', 'expériences',
            'expérimental', 'expérimenter',

            // === È ===
            'accès', 'après', 'bibliothèque', 'carrière', 'caractère', 'célèbre',
            'chèque', 'collègue', 'complète', 'complètement', 'concrète',
            'considère', 'critère', 'critères', 'dernière', 'deuxième',
            'espèce', 'fidèle', 'fièvre', 'frontière', 'légère', 'lumière',
            'manière', 'matière', 'matières', 'modèle', 'modèles',
            'mystère', 'poème', 'première',
            'premièrement', 'problème', 'problèmes', 'progrès', 'prospère',
            'règle', 'règlement', 'repère', 'scène', 'sévère', 'sincère',
            'sphère', 'stratège', 'système', 'systèmes', 'théorème', 'troisième',
            'très',

            // === Ê ===
            'arrêter', 'arrêté', 'enquête', 'fenêtre', 'intérêt',

            // === Ç ===
            'aperçu', 'commerçant', 'déçu', 'façon', 'façons', 'français',
            'française', 'françaises', 'garçon', 'leçon', 'leçons', 'reçu',
            'reçue', 'soupçon',

            // === Mots avec multiples accents ===
            'accéder', 'accélérer', 'activité', 'actualité', 'abréviation', 'abréger',
            'agréable', 'améliorer', 'amélioration', 'amélioré', 'année', 'années',
            'bénéfice', 'bénéficier', 'bénévole',
            'capacité', 'catégorie', 'compétence', 'compétences', 'conséquent',
            'conséquence', 'considéré', 'considérer', 'créé', 'créer', 'création',
            'définition', 'définir', 'défini', 'démarche', 'démarrer',
            'déploiement', 'déployer', 'dépendre', 'déroulement', 'désormais',
            'détailler', 'déterminer', 'développement', 'développer', 'développeur',
            'développeurs', 'différent', 'différents', 'différence', 'difficulté',
            'difficultés', 'disponibilité', 'donnée', 'données',
            'efficacité',
            'fréquemment', 'fréquence', 'général', 'générale', 'généralement',
            'générer', 'génération', 'gérer',
            'héberger', 'hébergement', 'héritage',
            'idée', 'idées', 'immédiatement', 'ingénieur', 'ingénierie',
            'intégrer', 'intégration', 'intégrité', 'intéressant', 'intéresser',
            'intermédiaire',
            'léger', 'licencié',
            'mathématiques', 'mathématique', 'mécanique', 'médecin', 'mémoire',
            'mémoires', 'méthode', 'méthodes',
            'nécessaire', 'nécessairement', 'nécessité', 'numéro', 'numérique',
            'opéré', 'opération', 'opérateur', 'opportunité',
            'paramètre', 'paramètres', 'particulièrement', 'particulière',
            'pédagogie', 'pédagogique', 'période', 'phénomène',
            'possibilité', 'possibilités', 'précis', 'précision', 'préférer',
            'préférence', 'préparation', 'préparer', 'présentation', 'présenter',
            'précédent', 'procédure', 'procéder', 'propriété', 'propriétés',
            'protéger',
            'qualité', 'quantité',
            'réaliser', 'réalisation', 'réalité', 'récemment', 'récupérer',
            'récupération', 'rédiger', 'rédaction', 'référence', 'références',
            'réflexion', 'régulièrement', 'régulier', 'répéter', 'répétition',
            'répondre', 'réponse', 'représenter', 'réseau', 'réseaux',
            'résoudre', 'résultat', 'résultats', 'résumer', 'résumé',
            'réussi', 'réussite', 'réussir', 'révéler', 'révolution',
            'scénario', 'sécurité', 'société', 'spécificité', 'spécifique',
            'spécifiques', 'stratégie', 'stratégies', 'supérieur', 'supérieure',
            'télécharger', 'téléchargement', 'théorie', 'théorique',
            'université', 'universités', 'utilité',
            'vérité', 'vérifier', 'vérification',
            'déjà', 'voilà',
            'bientôt', 'contrôle', 'contrôler', 'contrôleur', 'diplôme',
            'hôpital', 'hôtel', 'impôt', 'plutôt', 'rôle', 'symptôme',

            // === Termes techniques accentués ===
            'académique', 'académiques', 'académie',
            'complété', 'complétée', 'compléter', 'complémentaire', 'complémentaires',
            'décision', 'décisions', 'décrire', 'décrit',
            'défaut', 'défauts', 'défense', 'défenses',
            'délai', 'délais', 'déléguer',
            'géométrie', 'géographique', 'géographie',
            'implémentation', 'implémentations', 'implémenter', 'implémenté',
            'médecine', 'médical', 'médicale', 'médias',
            'méthodologie', 'méthodologies', 'métier', 'métiers',
            'métrique', 'métriques',
            'précédent', 'précédents', 'précédente', 'précédentes',
            'réduction', 'réduire', 'répertoire', 'répertoires',
            'réparer', 'réparation', 'réputation',
            'réviser', 'révision', 'révisions',
            'séparation', 'séparément', 'séquence', 'séquences',
            'sélection', 'sélectionner', 'sélectionné', 'sélectionnée',
            'spécialisé', 'spécialisée', 'spécialisation', 'spécialiser',
            'téléphone', 'télécommunication', 'téléphonie', 'télécharger',
            'prénom', 'prénoms', 'présenté', 'présentée',
            'déterminé', 'déterminée', 'détermination',
            'négocier', 'négociation', 'négociations',
            'bénéfique', 'bénéfiques',
            'compétitif', 'compétitive', 'compétitifs',
            'modélisation', 'modéliser', 'modélisé',
            'sécuriser', 'sécurisation', 'sécurisé',
            'gérée', 'géré', 'gérer',
            'créativité', 'créatif', 'créative',
            'logiciel', 'logiciels',
            'formulaire', 'formulaires',
            'commentaire', 'commentaires',
            'référentiel', 'référentiels',
            'démarrage', 'démarrer', 'démarré',
            'réseau', 'réseaux',
            'présent', 'présente', 'présentement',
        ];

        $dict = [];
        foreach ($accentedWords as $word) {
            $stripped = $this->stripAccents($word);
            if ($stripped !== $word) {
                // Ne pas ajouter les mots courts ambigus
                if (!in_array($stripped, $this->safeWords)) {
                    $dict[$stripped] = $word;
                }
            }
        }

        return $dict;
    }

    private function getCommonFrenchWords(): array
    {
        return [
            'accompagner', 'accomplir', 'acquisition', 'algorithme', 'application',
            'apprendre', 'apprentissage', 'article', 'automatiquement',
            'beaucoup', 'calculateur', 'commencer', 'communication',
            'connaissance', 'connaissances', 'connexion',
            'difficile', 'dimension',
            'efficace', 'excellent', 'exemple',
            'fonction', 'framework',
            'important', 'indispensable', 'informatique', 'intelligent',
            'langage', 'longueur',
            'maintenance', 'maintenir', 'meilleur', 'mettre',
            'normalement', 'notamment',
            'objectif', 'organisation',
            'performance', 'permettre', 'plateforme', 'plusieurs',
            'pratique', 'programmation', 'puissant',
            'rapidement', 'recommandation',
            'seulement', 'significatif', 'susceptible',
            'technique', 'technologie',
            'utilisateur', 'vraiment',
            'avec', 'avoir', 'bien', 'chez', 'comme', 'dans', 'dire', 'donc',
            'encore', 'entre', 'faire', 'jamais', 'jour', 'mais', 'monde',
            'notre', 'nous', 'part', 'pays', 'petit', 'plus', 'point',
            'pour', 'premier', 'puis', 'quand', 'sans', 'tout', 'vous',
            'aussi', 'autre', 'avant', 'cette', 'chose', 'depuis', 'deux',
            'droit', 'grand', 'homme', 'moment', 'parce', 'place', 'rien',
            'temps', 'toujours', 'trois', 'venir', 'voir', 'projet', 'travail',
            'contenu', 'titre', 'texte', 'image', 'page', 'site',
            'mise', 'base', 'note', 'code', 'ligne', 'liste', 'forme',
            'suite', 'reste', 'groupe', 'niveau', 'valeur', 'recherche',
            'cours', 'nombre', 'besoin', 'moyen', 'effet', 'cause',
            'raison', 'question', 'reponse', 'solution', 'action',
            'chaque', 'aucun', 'certain', 'possible', 'nouveau',
            'simple', 'seule', 'propre', 'libre', 'plein', 'haut',
            'long', 'jeune', 'ancien', 'dernier', 'suivant', 'entier',
            'quelque', 'espace', 'emploi', 'pierre',
            // Termes techniques pour Levenshtein
            'analyse', 'analyses', 'analytique',
            'architecture', 'architectures',
            'authentification',
            'commentaire', 'commentaires',
            'configuration', 'configurations',
            'connexion', 'connexions',
            'description', 'descriptions',
            'documentation',
            'fonctionnel', 'fonctionnelle',
            'formulaire', 'formulaires',
            'gestion', 'gestions',
            'identification',
            'interface', 'interfaces',
            'introduction',
            'logiciel', 'logiciels',
            'modification', 'modifications',
            'occurrence', 'occurrences',
            'persistance',
            'processus',
            'professionnel', 'professionnelle',
            'programme', 'programmes',
            'relation', 'relations',
            'serveur', 'serveurs',
            'simulation', 'simulations',
            'structure', 'structures',
            'tableau', 'tableaux',
            'utilisateur', 'utilisateurs',
            'validation', 'validations',
            'variable', 'variables',
            'classe', 'classes',
            'fichier', 'fichiers',
            'module', 'modules',
            'service', 'services',
            'contenu', 'contenus',
            'accéder', 'répertoire',
        ];
    }
}
