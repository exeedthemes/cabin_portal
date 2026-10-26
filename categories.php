<?php
/** Shared categories keep public summaries and fee classification consistent. */
function af_item_categories(): array {
    return [
        'chargers' => ['label' => 'Chargers, cables & power banks', 'public' => 'Charger or cable', 'legacy_fee' => 'electronics', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:(?:phone|laptop|usb)[ -]?(?:chargers?|cables?)|chargers?|charging cables?|cables?|adapters?|adaptors?|power[ -]?banks?|batter(?:y|ies)|ladeger[aä]t(?:e)?|ladekabel)(?![\\p{L}\\p{N}])~iu'],
        'bags' => ['label' => 'Bags & luggage', 'public' => 'Bag or luggage', 'legacy_fee' => 'bags', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:(?:laptop|camera|hand|shopping|travel|gym|school)[ -]?bags?|bags?|back[ -]?packs?|rucksacks?|suitcases?|luggage|briefcases?|tote|koffer|taschen?|rucks[aä]cke)(?![\\p{L}\\p{N}])~iu'],
        'wallets' => ['label' => 'Wallets & purses', 'public' => 'Wallet or purse', 'legacy_fee' => 'wallets', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:passport holders?|card[ -]?holders?|wallets?|purses?|portemonnaies?|geldb[oö]rsen?)(?![\\p{L}\\p{N}])~iu'],
        'documents' => ['label' => 'Documents & identity cards', 'public' => 'Identity document', 'legacy_fee' => 'documents', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:passports?|identity(?: cards?)?|id[ -]?cards?|driv(?:ing|er)[ -]?(?:licen[cs]es?)|boarding passes?|tickets?|documents?|certificates?|ausweis(?:e)?|reisep[aä]sse?|f[uü]hrerschein(?:e)?)(?![\\p{L}\\p{N}])~iu'],
        'phones' => ['label' => 'Phones & tablets', 'public' => 'Phone or tablet', 'legacy_fee' => 'electronics', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:phones?|smartphones?|iphones?|ipads?|tablets?|handys?|galaxy|kindles?|e[ -]?readers?)(?![\\p{L}\\p{N}])~iu'],
        'computers' => ['label' => 'Laptops & computers', 'public' => 'Computer', 'legacy_fee' => 'electronics', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:laptops?|computers?|mac[ -]?books?|notebooks?|chromebooks?|surface)(?![\\p{L}\\p{N}])~iu'],
        'audio' => ['label' => 'Headphones & audio', 'public' => 'Headphones or audio device', 'legacy_fee' => 'electronics', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:head[ -]?phones?|ear[ -]?phones?|ear[ -]?buds?|ear[ -]?pods?|earods?|air[ -]?pods?|speakers?|headsets?|kopfh[oö]rer)(?![\\p{L}\\p{N}])~iu'],
        'cameras' => ['label' => 'Cameras & accessories', 'public' => 'Camera or accessory', 'legacy_fee' => 'electronics', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:cameras?|lens(?:es)?|tripods?|gopros?|kameras?|objektiv(?:e)?)(?![\\p{L}\\p{N}])~iu'],
        'watches' => ['label' => 'Watches & smartwatches', 'public' => 'Watch', 'legacy_fee' => 'electronics', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:(?:smart|apple)[ -]?watch(?:es)?|watch(?:es)?|wristwatch(?:es)?|armbanduhr(?:en)?|uhren?)(?![\\p{L}\\p{N}])~iu'],
        'electronics' => ['label' => 'Other electronics', 'public' => 'Electronics', 'legacy_fee' => 'electronics', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:electronics?|game consoles?|nintendo|playstation|xbox|controllers?|calculators?|usb[ -]?(?:sticks?|drives?)|hard[ -]?drives?|mice|mouse|keyboards?|fernbedienung(?:en)?)(?![\\p{L}\\p{N}])~iu'],
        'keys' => ['label' => 'Keys & keyrings', 'public' => 'Keys', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:keys?|key[ -]?rings?|key[ -]?chains?|key[ -]?fobs?|schl[uü]ssel(?:bund)?)(?![\\p{L}\\p{N}])~iu'],
        'jewelry' => ['label' => 'Jewelry', 'public' => 'Jewelry', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:jewel(?:ry|lery)|rings?|earrings?|necklaces?|bracelets?|pendants?|brooch(?:es)?|schmuck|ohrringe?|halsketten?|armb[aä]nder)(?![\\p{L}\\p{N}])~iu'],
        'glasses' => ['label' => 'Glasses & sunglasses', 'public' => 'Glasses', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:(?:sun|reading|eye|swimming)[ -]?glasses|glasses|spectacles|goggles|sonnenbrille(?:n)?|brille(?:n)?)(?![\\p{L}\\p{N}])~iu'],
        'clothing' => ['label' => 'Clothing & accessories', 'public' => 'Clothing', 'legacy_fee' => 'clothing', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:jackets?|coats?|hats?|caps?|scar(?:f|ves)|gloves?|clothing|clothes|pullovers?|hoodies?|sweaters?|shirts?|t[ -]?shirts?|trousers?|pants|dresses|skirts?|belts?|ties|socks?|jacken?|m[aä]ntel|m[uü]tzen?|schals?|handschuhe?)(?![\\p{L}\\p{N}])~iu'],
        'footwear' => ['label' => 'Shoes & footwear', 'public' => 'Footwear', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:shoes?|boots?|sneakers?|trainers?|sandals?|slippers?|flip[ -]?flops?|schuhe?|stiefel)(?![\\p{L}\\p{N}])~iu'],
        'umbrellas' => ['label' => 'Umbrellas', 'public' => 'Umbrella', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:umbrellas?|parasols?|regenschirm(?:e)?)(?![\\p{L}\\p{N}])~iu'],
        'books' => ['label' => 'Books & reading material', 'public' => 'Book or reading material', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:books?|novels?|magazines?|newspapers?|bibles?|b[uü]cher|roman(?:e)?|zeitschriften?)(?![\\p{L}\\p{N}])~iu'],
        'stationery' => ['label' => 'Stationery & files', 'public' => 'Stationery', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:pens?|pencils?|stationery|folders?|files?|diar(?:y|ies)|journals?|notepads?|sketchbooks?|kugelschreiber|stifte?|mappen?)(?![\\p{L}\\p{N}])~iu'],
        'toys' => ['label' => 'Toys & games', 'public' => 'Toy or game', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:toys?|games?|tedd(?:y|ies)|dolls?|stuffed animals?|plush(?:ies)?|puzzles?|lego|spielzeug|kuscheltiere?)(?![\\p{L}\\p{N}])~iu'],
        'baby' => ['label' => 'Baby & child equipment', 'public' => 'Baby or child item', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:baby[ -]?(?:bottles?|blankets?|carriers?)|strollers?|pushchairs?|prams?|pacifiers?|dummies|nappies|diapers?|kinderwagen|schnuller)(?![\\p{L}\\p{N}])~iu'],
        'medical' => ['label' => 'Medical & accessibility items', 'public' => 'Medical or accessibility item', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:medicines?|medications?|pills?|insulin|inhalers?|hearing[ -]?aids?|crutches|wheelchairs?|walking[ -]?(?:sticks?|aids?)|cpap|medical|medikamente?|geh[oö]rger[aä]te?)(?![\\p{L}\\p{N}])~iu'],
        'toiletries' => ['label' => 'Toiletries & cosmetics', 'public' => 'Toiletry or cosmetic item', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:toiletries|cosmetics?|make[ -]?up|perfumes?|toothbrush(?:es)?|razors?|hair[ -]?brush(?:es)?|brush(?:es)?|shampoo|lotions?|creams?|parf[uü]m|zahnb[uü]rsten?)(?![\\p{L}\\p{N}])~iu'],
        'bottles' => ['label' => 'Bottles, cups & containers', 'public' => 'Bottle or container', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:water[ -]?bottles?|bottles?|flasks?|thermos|cups?|mugs?|lunch[ -]?boxes?|containers?|trinkflaschen?|flaschen?|becher)(?![\\p{L}\\p{N}])~iu'],
        'food' => ['label' => 'Food & drinks', 'public' => 'Food or drink', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:food|drinks?|snacks?|chocolates?|sweets?|candy|sandwich(?:es)?|lebensmittel|getr[aä]nke?)(?![\\p{L}\\p{N}])~iu'],
        'cash' => ['label' => 'Cash & payment cards', 'public' => 'Cash or payment card', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:cash|money|coins?|banknotes?|credit[ -]?cards?|debit[ -]?cards?|bank[ -]?cards?|bargeld|geld|kreditkarten?|bankkarten?)(?![\\p{L}\\p{N}])~iu'],
        'sports' => ['label' => 'Sports equipment', 'public' => 'Sports item', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:sports?[ -]?(?:equipment|gear)|footballs?|basketballs?|rackets?|racquets?|yoga[ -]?mats?|helmets?|skis?|skateboards?|sportger[aä]te?)(?![\\p{L}\\p{N}])~iu'],
        'instruments' => ['label' => 'Musical instruments', 'public' => 'Musical instrument', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:guitars?|violins?|flutes?|ukuleles?|saxophones?|trumpets?|musical[ -]?instruments?|gitarren?|geigen?)(?![\\p{L}\\p{N}])~iu'],
        'travel' => ['label' => 'Travel accessories', 'public' => 'Travel accessory', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:travel[ -]?pillows?|neck[ -]?pillows?|sleep[ -]?masks?|eye[ -]?masks?|luggage[ -]?tags?|nackenkissen|reisekissen)(?![\\p{L}\\p{N}])~iu'],
        'smoking' => ['label' => 'Smoking & vaping items', 'public' => 'Smoking or vaping item', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:cigarettes?|cigrates?|cigars?|vapes?|e[ -]?cigarettes?|lighters?|tobacco|zigaretten?|feuerzeuge?)(?![\\p{L}\\p{N}])~iu'],
        'other' => ['label' => 'Other items', 'public' => 'Personal item', 'legacy_fee' => 'other', 'pattern' => '~(?<![\\p{L}\\p{N}])(?:(?!))(?![\\p{L}\\p{N}])~iu'],
    ];
}

/** The first mentioned item wins, so contents do not replace a bag's category.
 * Specific compounds (e.g. laptop bag or phone charger) win ties. */
function af_detect_item_category(string $description): string {
    $best = 'other'; $offset = PHP_INT_MAX;
    foreach (af_item_categories() as $key => $category) {
        if (preg_match($category['pattern'], $description, $match, PREG_OFFSET_CAPTURE) && $match[0][1] < $offset) {
            $best = $key; $offset = $match[0][1];
        }
    }
    return $best;
}
/** Only the generic label is public; private identifying details stay hidden. */
function af_public_item_category(string $description): string {
    return af_item_categories()[af_detect_item_category($description)]['public'];
}

