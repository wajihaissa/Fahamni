<?php

namespace App\Service;

class BadWordDetector
{
    private array $badWords = [
        // =====================================================================
        // FRANÇAIS — Insultes générales
        // =====================================================================
        'merde', 'merdeux', 'merdeuse',
        'putain', 'pute', 'pétasse', 'petasse', 'prostituée', 'prostituee',
        'connard', 'connasse', 'conasse',
        'salaud', 'salope', 'salopard', 'saloperie',
        'enculé', 'encule', 'enculer', 'enculeur',
        'nique', 'niquer', 'niqué',
        'ntm', 'fdp', 'tg',
        'fils de pute', 'fils de putain', 'fille de pute',
        'ta gueule', 'ferme ta gueule', 'ferme-la',
        'va te faire', 'va te faire foutre', 'va chier',
        'batard', 'bâtard', 'batarde',
        'abruti', 'abrutie', 'abrutis',
        'débile', 'debile', 'débiles',
        'crétin', 'cretine', 'crétin',
        'imbécile', 'imbecile',
        'ordure', 'ordures',
        'pourriture',
        'dégage', 'degage', 'dégagez',
        'bite', 'bites', 'couille', 'couilles', 'couillon',
        'chier', 'chie', 'chieur', 'chieuse',
        'foutre', 'foutaise', 'foutaises',
        'bordel', 'dégueulasse', 'degueulasse',
        'bouffon', 'bouffonne',
        'trou du cul', 'trouduc', 'trou-duc',
        'enfoiré', 'enfoire', 'enfoirée',
        'vermine', 'racaille',
        'mongol', 'mongolien', 'mongolienne',
        // Insultes racistes/discriminatoires FR
        'nègre', 'negre', 'négresse', 'negresse',
        'bougnoule', 'bougnoul',
        'raton', 'bicot', 'bamboula',
        'youpin', 'youpine',
        // Insultes homophobes FR
        'pédé', 'pede', 'pédale', 'pedale',
        'tapette', 'gouine',
        // =====================================================================
        // ANGLAIS — Insultes courantes
        // =====================================================================
        'fuck', 'fucking', 'fucked', 'fucker', 'fuckoff', 'fck', 'f*ck',
        'shit', 'sh1t', 'bullshit', 'bullsh1t',
        'asshole', 'ass hole',
        'bitch', 'bitches', 'b1tch',
        'bastard', 'bastards',
        'damn', 'goddamn',
        'dick', 'dicks',
        'pussy', 'cunt', 'cunts',
        'cock', 'cocks',
        'whore', 'slut', 'hoe',
        'idiot', 'stupid', 'moron', 'dumb',
        'sucker', 'loser', 'retard',
        'stfu', 'wtf', 'kys',
        'go die', 'kill yourself',
        'motherfucker', 'mf',
        'son of a bitch', 'sob',
        // Insultes racistes/discriminatoires EN
        'nigger', 'nigga', 'n1gger', 'n1gga',
        'faggot', 'fag', 'dyke',
        // =====================================================================
        // ARABE TUNISIEN (Derja) — en caractères latins
        // =====================================================================
        'msatek', 'msakha',
        'zamel', 'zamla', 'zamil',
        'kahba', 'qahba',
        'kelb', 'kalb', 'kilab',
        'manyak', 'manyok', 'manyek', 'manyouk',
        'zebbi', 'zebi', 'zeb', 'zebb', 'zbeb',
        'nayek', 'naik', 'nik', 'nikk', 'nikomok', 'nik omek', 'nik okhtek',
        'koss', 'kossomek', 'koss ommek', 'koss omok', 'koss omek',
        'thalla', 'thali',
        'barra', 'tozz', 'toz', 'tozzfik',
        'hmar', 'hmara', 'bhim', 'bhima', 'bheima',
        'maset', 'mastek', 'meset', 'mastek', 'messtek',
        'haywan', 'haywana', 'haywane', 'hayawane',
        'basla', 'baslaoui', 'baslounia', 'baslouni',
        'wisekh', 'wosekh', 'weskha', 'wisakh', 'wsekh',
        'miboun', 'mabyoun', 'mabyouna', 'mabyoun',
        'ta7an', 'tahan', 'tahhan', 'ta7ana',
        'karba', 'harboucha', 'harbouch',
        'chkoun rabbek', 'chkoun omek',
        'yezzi', 'bouzbal',
        'hashek', 'ya7chik', 'ya hchik',
        'ya3ayel', 'w3el', 'wa3el',
        'nayla', 'naylou',
        'yel3en', 'la3nek', 'lakhtek', 'la3en',
        'bzoula', 'bziwla',
        'ykhzi', 'ykhzik',
        'ya khinzir', 'khinzir',
        'ya hmar',
        // =====================================================================
        // ARABE STANDARD — en caractères latins
        // =====================================================================
        'himar', 'ibn el kalb', 'sharmouta', 'sharmout', 'charmouta',
        'khanzeir', 'khanzir', 'khanzeer',
        'ahbal', 'ahmak', 'ghabi', 'gaabi',
        'ibn el sharmouta', 'bint el sharmouta',
        'kol khara', 'koul khara', 'khara',
        'tfouh', 'tfou', 'tfeh',
        'yakhrib', 'yakhreb', 'yakhrib betak',
        'weld el kahba', 'bent el kahba',
        'ibn el haram', 'weld el haram',
        'yel3an omak', 'yel3an deenak',
        // =====================================================================
        // ARABE — Script arabe
        // =====================================================================
        'كلب', 'حمار', 'قحبة', 'زبي', 'نيك',
        'شرموطة', 'خنزير', 'أحمق', 'غبي', 'خرا',
        'عاهرة', 'كس', 'طيز', 'منيوك',
        'يلعن', 'لعين', 'ملعون', 'قذر', 'وسخ',
        'حيوان', 'وليد الكلب', 'ابن القحبة',
        'اخرس', 'روح من هنا', 'يخزيك',
    ];

    public function containsBadWords(string $text): bool
    {
        return count($this->findBadWords($text)) > 0;
    }

    public function findBadWords(string $text): array
    {
        $textLower = mb_strtolower($text);
        $found = [];

        foreach ($this->badWords as $word) {
            if (mb_strpos($textLower, mb_strtolower($word)) !== false) {
                $found[] = $word;
            }
        }

        return array_unique($found);
    }
}
