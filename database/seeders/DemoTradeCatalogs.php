<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Extra demo catalogs. Two companies share each catalog so a sales invoice
 * can be linked as a purchase invoice.
 */
final class DemoTradeCatalogs
{
    /**
     * @return array<string, array<string, mixed>>|null
     */
    public static function find(string $catalog): ?array
    {
        return self::all()[$catalog] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function all(): array
    {
        return [
            'bakery' => self::bakery(),
            'snacks' => self::snacks(),
            'home' => self::home(),
            'coffee' => self::coffee(),
            'pantry' => self::pantry(),
        ];
    }

    /**
     * @param  list<array{code: string, name: string, color: string, description: string}>  $children
     * @return list<array{code: string, name: string, color: string, description: string, children?: list<array<string, mixed>>}>
     */
    private static function tree(string $code, string $name, string $color, string $description, array $children, string $packDescription): array
    {
        return [
            [
                'code' => $code,
                'name' => $name,
                'color' => $color,
                'description' => $description,
                'children' => $children,
            ],
            [
                'code' => 'PKG',
                'name' => 'Packaging',
                'color' => '#78909C',
                'description' => $packDescription,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bakery(): array
    {
        return [
            'owner' => 'dayra',
            'brand' => 'DAYRA',
            'barcode_prefix' => '531',
            'freight_plu' => '4098',
            'categories' => self::tree('BKY', 'Bakery', '#A1887F', 'Bread and pastry from Dayra Bakery.', [
                ['code' => 'BKY-BRD', 'name' => 'Bread', 'color' => '#D7CCC8', 'description' => 'Arabic bread, markouk, and toast.'],
                ['code' => 'BKY-PST', 'name' => 'Pastry', 'color' => '#BCAAA4', 'description' => 'Croissant, manaqish, and buns.'],
                ['code' => 'BKY-SWT', 'name' => 'Sweets', 'color' => '#8D6E63', 'description' => 'Cookies, maamoul, and cake.'],
            ], 'Paper bags used to pack bread.'),
            'brands' => ['DAYRA' => 'Dayra Bakery', 'PAPERBAG' => 'Bourj Bag Works'],
            'lines' => [
                ['DY-BREAD-WHT', '4001', 'Dayra White Arabic Bread', 'BKY-BRD', '0.7500', '0.3500', '20', '120', '480'],
                ['DY-BREAD-BRN', '4002', 'Dayra Brown Arabic Bread', 'BKY-BRD', '0.8500', '0.4000', '20', '80', '320'],
                ['DY-BREAD-SM', '4003', 'Dayra Small Arabic Bread', 'BKY-BRD', '0.5500', '0.2500', '30', '140', '560'],
                ['DY-MARKOUK', '4004', 'Dayra Markouk Bread', 'BKY-BRD', '1.2500', '0.6000', '10', '60', '240'],
                ['DY-SAJ', '4005', 'Dayra Saj Bread', 'BKY-BRD', '1.1500', '0.5500', '10', '50', '200'],
                ['DY-KAJ-SES', '4006', 'Dayra Sesame Kaak', 'BKY-BRD', '0.6500', '0.3000', '24', '100', '400'],
                ['DY-TOAST-500', '4007', 'Dayra Toast Bread 500g', 'BKY-BRD', '1.3500', '0.7200', '12', '90', '360'],
                ['DY-TOAST-BRN', '4008', 'Dayra Brown Toast 500g', 'BKY-BRD', '1.4500', '0.7800', '12', '70', '280'],
                ['DY-BUN-BUR', '4009', 'Dayra Burger Buns 4', 'BKY-PST', '1.2500', '0.6200', '12', '80', '320'],
                ['DY-BUN-HOT', '4010', 'Dayra Hot Dog Buns 4', 'BKY-PST', '1.1500', '0.5800', '12', '70', '280'],
                ['DY-CROISS', '4011', 'Dayra Butter Croissant', 'BKY-PST', '0.9500', '0.4500', '20', '100', '400'],
                ['DY-PAINCHOC', '4012', 'Dayra Pain au Chocolat', 'BKY-PST', '1.0500', '0.5000', '20', '90', '360'],
                ['DY-MANA-ZAAT', '4013', 'Dayra Zaatar Manaqish', 'BKY-PST', '0.8500', '0.4000', '12', '80', '320'],
                ['DY-MANA-CHS', '4014', 'Dayra Cheese Manaqish', 'BKY-PST', '1.1500', '0.5500', '12', '70', '280'],
                ['DY-FATAYER', '4015', 'Dayra Spinach Fatayer', 'BKY-PST', '1.2500', '0.6000', '12', '60', '240'],
                ['DY-CAKE-VAN', '4016', 'Dayra Vanilla Cake Slice', 'BKY-SWT', '1.7500', '0.8500', '12', '40', '160'],
                ['DY-CAKE-CHO', '4017', 'Dayra Chocolate Cake Slice', 'BKY-SWT', '1.8500', '0.9000', '12', '40', '160'],
                ['DY-COOKIE-BUT', '4018', 'Dayra Butter Cookies 200g', 'BKY-SWT', '2.2500', '1.2000', '12', '60', '240'],
                ['DY-COOKIE-CHO', '4019', 'Dayra Chocolate Cookies 200g', 'BKY-SWT', '2.3500', '1.2500', '12', '50', '200'],
                ['DY-MAAMOUL', '4020', 'Dayra Date Maamoul 500g', 'BKY-SWT', '4.5000', '2.4000', '8', '40', '160'],
                ['DY-GHRIBE', '4021', 'Dayra Ghraybeh 300g', 'BKY-SWT', '3.2500', '1.7000', '8', '36', '144'],
                ['DY-BREADSTK', '4022', 'Dayra Breadsticks 150g', 'BKY-BRD', '1.1500', '0.5500', '12', '70', '280'],
            ],
            'packaging' => ['DY-BAG', '4090', 'Bread Paper Bag', 'PAPERBAG', 'PKG', 'breadbag', '0.0400', '0.0150', '500', '3000', '12000', 'Plain bread bag. Purchase unit is a pack of 500.'],
            'produce' => ['DY-CROISS-4', '4080', 'Dayra Croissant 4 Pack', 'BKY-PST', '3.6000', '1.8000', 'Pack of four Dayra butter croissants. Built from DY-CROISS.', 'DY-CROISS', '4'],
            'bundle' => ['DY-MORNING', '4081', 'Dayra Morning Box', 'BKY', '6.5000', '3.4000', 'Toast, two croissants, and two pains au chocolat.', ['DY-TOAST-500' => '1', 'DY-CROISS' => '2', 'DY-PAINCHOC' => '2']],
            'customers' => [
                ['CUST-HAMCAFE', 'Hamra Morning Cafe', 'hamra.morning@gmail.com', '+961 1 680 100', 'horeca', 'bank', 'net30', '601-840101', 'Cafe. Not a tenant on this system.', 'Hamra Street', 'Beirut', 'Beirut', 'Hamra Morning Buyer'],
                ['CUST-ASHBAKE', 'Achrafieh Corner Bakery', 'achrafieh.corner@gmail.com', '+961 1 680 110', 'retail', 'cash', 'due', '601-840102', 'Neighborhood bakery counter. Not a tenant on this system.', 'Sassine', 'Beirut', 'Beirut', 'Achrafieh Corner Owner'],
                ['CUST-AUBCANT', 'Campus Canteen', 'campus.canteen@gmail.com', '+961 1 680 120', 'key', 'bank', 'net30', '601-840103', 'Campus canteen. Not a tenant on this system.', 'Bliss Street', 'Beirut', 'Beirut', 'Campus Canteen Buyer'],
                ['CUST-JOUNBAKE', 'Jounieh Beach Snack', 'jounieh.snack@gmail.com', '+961 9 680 130', 'horeca', 'card', 'due', '601-840104', 'Snack bar. Not a tenant on this system.', 'Coast Road', 'Jounieh', 'Mount Lebanon', 'Jounieh Snack Buyer'],
                ['CUST-ZAHLEHOT', 'Zahle Hotel Breakfast', 'zahle.breakfast@gmail.com', '+961 8 680 140', 'horeca', 'bank', 'net30', '601-840105', 'Hotel breakfast. Not a tenant on this system.', 'Boulevard', 'Zahle', 'Bekaa', 'Zahle Breakfast Buyer'],
                ['CUST-DORAMINI', 'Dora Mini Market', 'dora.minimarket@gmail.com', '+961 1 680 150', 'retail', 'cash', 'due', '601-840106', 'Mini market. Not a tenant on this system.', 'Dora Highway', 'Dora', 'Mount Lebanon', 'Dora Mini Market Owner'],
                ['CUST-TRIPBAKE', 'Tripoli School Kitchen', 'tripoli.school@gmail.com', '+961 6 680 160', 'key', 'bank', 'net30', '601-840107', 'School kitchen. Not a tenant on this system.', 'Azmi Street', 'Tripoli', 'North Lebanon', 'Tripoli School Buyer'],
                ['CUST-SAIDACAFE2', 'Saida Corniche Cafe', 'saida.corniche@gmail.com', '+961 7 680 170', 'horeca', 'card', 'due', '601-840108', 'Cafe. Not a tenant on this system.', 'Corniche', 'Saida', 'South Lebanon', 'Saida Corniche Buyer'],
            ],
            'supplier' => ['SUP-BREADBAG', 'Bourj Bag Works', 'Bourj Bag Works SAL', 'bourj.bags@gmail.com', '+961 1 680 400', 'pkg', '601-840201', 'Paper bag supplier. Not a tenant on this system.', 'Industrial Street', 'Bourj Hammoud', 'Mount Lebanon', 'Bourj Bag Works Sales', 'breadbag'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function snacks(): array
    {
        return [
            'owner' => 'qarya',
            'brand' => 'QARYA',
            'barcode_prefix' => '532',
            'freight_plu' => '5098',
            'categories' => self::tree('SNA', 'Snacks', '#F9A825', 'Snacks packed by Qarya.', [
                ['code' => 'SNA-CHI', 'name' => 'Chips', 'color' => '#FDD835', 'description' => 'Potato chips, corn chips, and popcorn.'],
                ['code' => 'SNA-NUT', 'name' => 'Nuts and seeds', 'color' => '#8D6E63', 'description' => 'Nuts and roasted seeds.'],
                ['code' => 'SNA-BIS', 'name' => 'Biscuits', 'color' => '#FFB74D', 'description' => 'Biscuits and wafers.'],
                ['code' => 'SNA-CHO', 'name' => 'Chocolate and sweets', 'color' => '#6D4C41', 'description' => 'Chocolate, halva, and loukoum.'],
            ], 'Film used to pack snacks.'),
            'brands' => ['QARYA' => 'Qarya', 'SNACKFILM' => 'Bekaa Film'],
            'lines' => [
                ['QY-CHIPS-SALT', '5001', 'Qarya Salted Chips 40g', 'SNA-CHI', '1.2500', '0.6800', '24', '200', '800'],
                ['QY-CHIPS-CHL', '5002', 'Qarya Chili Chips 40g', 'SNA-CHI', '1.2500', '0.6800', '24', '180', '720'],
                ['QY-CHIPS-CHS', '5003', 'Qarya Cheese Chips 40g', 'SNA-CHI', '1.2500', '0.6800', '24', '180', '720'],
                ['QY-CHIPS-VIN', '5004', 'Qarya Vinegar Chips 40g', 'SNA-CHI', '1.2500', '0.6800', '24', '140', '560'],
                ['QY-CORN-CHS', '5005', 'Qarya Cheese Corn Chips 40g', 'SNA-CHI', '1.2000', '0.6500', '24', '160', '640'],
                ['QY-CORN-CHL', '5006', 'Qarya Chili Corn Chips 40g', 'SNA-CHI', '1.2000', '0.6500', '24', '140', '560'],
                ['QY-POP-SLT', '5007', 'Qarya Salted Popcorn 30g', 'SNA-CHI', '0.9500', '0.4800', '24', '160', '640'],
                ['QY-POP-CAR', '5008', 'Qarya Caramel Popcorn 30g', 'SNA-CHI', '0.9500', '0.4800', '24', '140', '560'],
                ['QY-NUT-ALM', '5009', 'Qarya Almonds 150g', 'SNA-NUT', '3.7500', '2.2000', '12', '60', '240'],
                ['QY-NUT-CSH', '5010', 'Qarya Cashews 150g', 'SNA-NUT', '4.2500', '2.6000', '12', '50', '200'],
                ['QY-NUT-MIX', '5011', 'Qarya Mixed Nuts 200g', 'SNA-NUT', '4.7500', '2.9000', '12', '50', '200'],
                ['QY-NUT-PEA', '5012', 'Qarya Roasted Peanuts 200g', 'SNA-NUT', '1.6500', '0.9000', '12', '90', '360'],
                ['QY-SEED-SUN', '5013', 'Qarya Sunflower Seeds 100g', 'SNA-NUT', '0.8500', '0.4200', '24', '120', '480'],
                ['QY-SEED-PUM', '5014', 'Qarya Pumpkin Seeds 100g', 'SNA-NUT', '1.4500', '0.8000', '24', '80', '320'],
                ['QY-BIS-TEA', '5015', 'Qarya Tea Biscuits 200g', 'SNA-BIS', '1.1500', '0.6200', '12', '100', '400'],
                ['QY-BIS-DIG', '5016', 'Qarya Digestive Biscuits 400g', 'SNA-BIS', '2.1500', '1.2000', '12', '80', '320'],
                ['QY-BIS-CHO', '5017', 'Qarya Chocolate Biscuits 150g', 'SNA-BIS', '1.4500', '0.7800', '12', '90', '360'],
                ['QY-WAFER-VAN', '5018', 'Qarya Vanilla Wafer 150g', 'SNA-BIS', '1.2500', '0.6800', '12', '90', '360'],
                ['QY-WAFER-HAZ', '5019', 'Qarya Hazelnut Wafer 150g', 'SNA-BIS', '1.3500', '0.7200', '12', '80', '320'],
                ['QY-CHO-MILK', '5020', 'Qarya Milk Chocolate 45g', 'SNA-CHO', '1.1500', '0.6400', '24', '160', '640'],
                ['QY-CHO-DARK', '5021', 'Qarya Dark Chocolate 45g', 'SNA-CHO', '1.2500', '0.7000', '24', '120', '480'],
                ['QY-CHO-CRM', '5022', 'Qarya Cream Bar 40g', 'SNA-CHO', '0.9500', '0.5000', '24', '140', '560'],
                ['QY-HALVA', '5023', 'Qarya Halva 200g', 'SNA-CHO', '2.4500', '1.3500', '12', '60', '240'],
                ['QY-LOKOUM', '5024', 'Qarya Loukoum 250g', 'SNA-CHO', '2.1500', '1.1500', '12', '50', '200'],
            ],
            'packaging' => ['QY-FILM', '5090', 'Snack Film Roll', 'SNACKFILM', 'PKG', 'snackfilm', '1.2500', '0.6500', '20', '80', '320', 'Printed snack film roll. Purchase unit is a pack of 20.'],
            'produce' => ['QY-CHIPS-6', '5080', 'Qarya Salted Chips 6 Pack', 'SNA-CHI', '6.9000', '4.0800', 'Pack of six Qarya salted chips. Built from QY-CHIPS-SALT.', 'QY-CHIPS-SALT', '6'],
            'bundle' => ['QY-MOVIE', '5081', 'Qarya Movie Box', 'SNA', '8.5000', '4.6000', 'Salted chips, caramel popcorn, and milk chocolate.', ['QY-CHIPS-SALT' => '2', 'QY-POP-CAR' => '2', 'QY-CHO-MILK' => '2']],
            'customers' => [
                ['CUST-CINEMETN', 'Metn Cinema Counter', 'metn.cinema@gmail.com', '+961 4 681 100', 'horeca', 'bank', 'net30', '601-850101', 'Cinema counter. Not a tenant on this system.', 'Mall Road', 'Dbayeh', 'Mount Lebanon', 'Metn Cinema Buyer'],
                ['CUST-UNICOOP', 'University Coop', 'university.coop@gmail.com', '+961 1 681 110', 'key', 'bank', 'net30', '601-850102', 'Campus shop. Not a tenant on this system.', 'Bliss Street', 'Beirut', 'Beirut', 'University Coop Buyer'],
                ['CUST-ZAHLEKIOSK', 'Zahle Bus Kiosk', 'zahle.kiosk@gmail.com', '+961 8 681 120', 'retail', 'cash', 'due', '601-850103', 'Kiosk. Not a tenant on this system.', 'Station Road', 'Zahle', 'Bekaa', 'Zahle Kiosk Owner'],
                ['CUST-JOUNKIOSK', 'Jounieh Highway Kiosk', 'jounieh.kiosk@gmail.com', '+961 9 681 130', 'retail', 'cash', 'due', '601-850104', 'Kiosk. Not a tenant on this system.', 'Highway', 'Jounieh', 'Mount Lebanon', 'Jounieh Kiosk Owner'],
                ['CUST-TRIPKIOSK', 'Tripoli Port Shop', 'tripoli.portshop@gmail.com', '+961 6 681 140', 'retail', 'card', 'due', '601-850105', 'Port shop. Not a tenant on this system.', 'Port Road', 'Tripoli', 'North Lebanon', 'Tripoli Port Buyer'],
                ['CUST-SAIDAKIOSK', 'Saida Souk Shop', 'saida.soukshop@gmail.com', '+961 7 681 150', 'retail', 'cash', 'due', '601-850106', 'Souk shop. Not a tenant on this system.', 'Old Souk', 'Saida', 'South Lebanon', 'Saida Souk Owner'],
                ['CUST-BEKAAWH', 'Bekaa Snack Wholesale', 'bekaa.snacks@gmail.com', '+961 8 681 160', 'wholesale', 'bank', 'net30', '601-850107', 'Snack wholesaler. Not a tenant on this system.', 'Main Road', 'Chtaura', 'Bekaa', 'Bekaa Snack Buyer'],
                ['CUST-SPORTCLUB', 'City Sport Club', 'city.sportclub@gmail.com', '+961 1 681 170', 'horeca', 'bank', 'net30', '601-850108', 'Sports club canteen. Not a tenant on this system.', 'Ramlet El Bayda', 'Beirut', 'Beirut', 'Sport Club Buyer'],
            ],
            'supplier' => ['SUP-SNACKFILM', 'Bekaa Film', 'Bekaa Film SAL', 'bekaa.film@gmail.com', '+961 8 681 400', 'pkg', '601-850201', 'Snack film supplier. Not a tenant on this system.', 'Industrial Zone', 'Chtaura', 'Bekaa', 'Bekaa Film Sales', 'snackfilm'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function home(): array
    {
        return [
            'owner' => 'rif',
            'brand' => 'RIF',
            'barcode_prefix' => '533',
            'freight_plu' => '6098',
            'categories' => self::tree('HPC', 'Household and personal care', '#26A69A', 'Home-care products from Rif.', [
                ['code' => 'HPC-CLN', 'name' => 'Cleaning', 'color' => '#4DB6AC', 'description' => 'Laundry, dish, and floor cleaners.'],
                ['code' => 'HPC-PC', 'name' => 'Personal care', 'color' => '#80CBC4', 'description' => 'Soap, shampoo, and tissue.'],
            ], 'Empty bottles used to pack cleaners.'),
            'brands' => ['RIF' => 'Rif', 'HOMEPACK' => 'Halat Plastics'],
            'lines' => [
                ['RF-DET-1KG', '6001', 'Rif Laundry Powder 1kg', 'HPC-CLN', '6.5000', '4.2000', '8', '70', '280'],
                ['RF-DET-2KG', '6002', 'Rif Laundry Powder 2kg', 'HPC-CLN', '11.5000', '7.4000', '4', '40', '160'],
                ['RF-DET-LIQ', '6003', 'Rif Liquid Detergent 1L', 'HPC-CLN', '5.7500', '3.6000', '12', '60', '240'],
                ['RF-DISH-500', '6004', 'Rif Dish Liquid 500ml', 'HPC-CLN', '2.7500', '1.7000', '12', '90', '360'],
                ['RF-DISH-1L', '6005', 'Rif Dish Liquid 1L', 'HPC-CLN', '4.2500', '2.6000', '12', '70', '280'],
                ['RF-BLEACH', '6006', 'Rif Bleach 1L', 'HPC-CLN', '1.6500', '0.8500', '12', '80', '320'],
                ['RF-FLOOR', '6007', 'Rif Floor Cleaner 1L', 'HPC-CLN', '2.4500', '1.3500', '12', '70', '280'],
                ['RF-GLASS', '6008', 'Rif Glass Cleaner 500ml', 'HPC-CLN', '1.9500', '1.0500', '12', '60', '240'],
                ['RF-SOFT', '6009', 'Rif Fabric Softener 1L', 'HPC-CLN', '3.4500', '2.0500', '12', '50', '200'],
                ['RF-SOAP-125', '6010', 'Rif Classic Soap 125g', 'HPC-PC', '0.8500', '0.4800', '48', '200', '800'],
                ['RF-SHAM-400', '6011', 'Rif Daily Shampoo 400ml', 'HPC-PC', '5.2500', '3.3000', '12', '50', '200'],
                ['RF-COND-400', '6012', 'Rif Daily Conditioner 400ml', 'HPC-PC', '5.2500', '3.3000', '12', '40', '160'],
                ['RF-SHOW-500', '6013', 'Rif Shower Gel 500ml', 'HPC-PC', '4.7500', '2.9000', '12', '50', '200'],
                ['RF-HAND-500', '6014', 'Rif Hand Wash 500ml', 'HPC-PC', '2.9500', '1.7000', '12', '70', '280'],
                ['RF-TISS-200', '6015', 'Rif Facial Tissues 200s', 'HPC-PC', '1.4500', '0.7800', '24', '140', '560'],
                ['RF-TOILET', '6016', 'Rif Toilet Paper 4', 'HPC-PC', '2.2500', '1.2000', '12', '90', '360'],
                ['RF-NAPKIN', '6017', 'Rif Table Napkins 100', 'HPC-PC', '1.1500', '0.5800', '24', '80', '320'],
                ['RF-BAG-30', '6018', 'Rif Trash Bags 30', 'HPC-CLN', '2.1500', '1.1500', '12', '70', '280'],
                ['RF-SPONGE', '6019', 'Rif Sponge 3', 'HPC-CLN', '1.2500', '0.6200', '24', '100', '400'],
                ['RF-WIPES', '6020', 'Rif Surface Wipes 80', 'HPC-CLN', '2.4500', '1.3500', '12', '60', '240'],
                ['RF-CREAM', '6021', 'Rif Hand Cream 75ml', 'HPC-PC', '2.7500', '1.5500', '12', '40', '160'],
                ['RF-TOOTH', '6022', 'Rif Toothpaste 100ml', 'HPC-PC', '2.1500', '1.2000', '24', '80', '320'],
            ],
            'packaging' => ['RF-BOTTLE', '6090', 'Empty Cleaner Bottle 1L', 'HOMEPACK', 'PKG', 'homepack', '0.1800', '0.0800', '100', '500', '2000', 'Empty 1 liter bottle. Purchase unit is a case of 100.'],
            'produce' => ['RF-SOAP-4', '6080', 'Rif Classic Soap 4 Pack', 'HPC-PC', '3.2000', '1.9200', 'Pack of four Rif classic soap bars. Built from RF-SOAP-125.', 'RF-SOAP-125', '4'],
            'bundle' => ['RF-CLEAN', '6081', 'Rif Kitchen Clean Box', 'HPC', '9.5000', '6.2000', 'Laundry powder, dish liquid, and sponges.', ['RF-DET-1KG' => '1', 'RF-DISH-500' => '1', 'RF-SPONGE' => '1']],
            'customers' => [
                ['CUST-HOTELHSK', 'City Hotel Housekeeping', 'city.hotel.house@gmail.com', '+961 1 682 100', 'key', 'bank', 'net30', '601-860101', 'Hotel housekeeping. Not a tenant on this system.', 'Minet El Hosn', 'Beirut', 'Beirut', 'Hotel Housekeeping Buyer'],
                ['CUST-CLINIC', 'Hamra Clinic Supply', 'hamra.clinic@gmail.com', '+961 1 682 110', 'key', 'bank', 'net30', '601-860102', 'Clinic supply. Not a tenant on this system.', 'Hamra Street', 'Beirut', 'Beirut', 'Hamra Clinic Buyer'],
                ['CUST-JDEIDEH', 'Jdeideh Family Market', 'jdeideh.market@gmail.com', '+961 1 682 120', 'retail', 'card', 'due', '601-860103', 'Supermarket. Not a tenant on this system.', 'Main Road', 'Jdeideh', 'Mount Lebanon', 'Jdeideh Market Buyer'],
                ['CUST-ZOUKMKT', 'Zouk Home Store', 'zouk.homestore@gmail.com', '+961 9 682 130', 'retail', 'card', 'due', '601-860104', 'Home store. Not a tenant on this system.', 'Highway', 'Zouk Mosbeh', 'Mount Lebanon', 'Zouk Home Buyer'],
                ['CUST-TRIPWH', 'Tripoli Janitorial Supply', 'tripoli.janitorial@gmail.com', '+961 6 682 140', 'wholesale', 'bank', 'net30', '601-860105', 'Janitorial wholesaler. Not a tenant on this system.', 'Azmi Street', 'Tripoli', 'North Lebanon', 'Tripoli Janitorial Buyer'],
                ['CUST-SAIDAHSK', 'Saida Apartments', 'saida.apartments@gmail.com', '+961 7 682 150', 'horeca', 'bank', 'net30', '601-860106', 'Building services. Not a tenant on this system.', 'Sea Road', 'Saida', 'South Lebanon', 'Saida Apartments Buyer'],
                ['CUST-SCHOOLCLN', 'Mount School Services', 'mount.school@gmail.com', '+961 5 682 160', 'key', 'bank', 'net30', '601-860107', 'School cleaning. Not a tenant on this system.', 'Main Road', 'Aley', 'Mount Lebanon', 'Mount School Buyer'],
                ['CUST-LAUNDRY', 'Dora Laundry Shop', 'dora.laundry@gmail.com', '+961 1 682 170', 'horeca', 'cash', 'due', '601-860108', 'Laundry shop. Not a tenant on this system.', 'Dora Highway', 'Dora', 'Mount Lebanon', 'Dora Laundry Owner'],
            ],
            'supplier' => ['SUP-HOMEPACK', 'Halat Plastics', 'Halat Plastics SAL', 'halat.plastics@gmail.com', '+961 9 682 400', 'pkg', '601-860201', 'Bottle supplier. Not a tenant on this system.', 'Industrial Road', 'Halat', 'Mount Lebanon', 'Halat Plastics Sales', 'homepack'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function coffee(): array
    {
        return [
            'owner' => 'nabea',
            'brand' => 'NABEA',
            'barcode_prefix' => '534',
            'freight_plu' => '7098',
            'categories' => self::tree('BEV', 'Coffee and tea', '#6D4C41', 'Coffee and tea from Nabea.', [
                ['code' => 'BEV-COF', 'name' => 'Coffee', 'color' => '#4E342E', 'description' => 'Beans, ground coffee, and instant coffee.'],
                ['code' => 'BEV-TEA', 'name' => 'Tea', 'color' => '#8D6E63', 'description' => 'Black, green, and herbal tea.'],
                ['code' => 'GRO-COC', 'name' => 'Cocoa and syrup', 'color' => '#A1887F', 'description' => 'Cocoa and coffee-bar syrups.'],
            ], 'Bags used to pack coffee.'),
            'brands' => ['NABEA' => 'Nabea', 'COFFEEBAG' => 'Gemmayze Bag Co'],
            'lines' => [
                ['NB-BEAN-250', '7001', 'Nabea Brazil Beans 250g', 'BEV-COF', '6.5000', '4.1000', '12', '80', '320'],
                ['NB-BEAN-1KG', '7002', 'Nabea Brazil Beans 1kg', 'BEV-COF', '22.0000', '14.0000', '6', '40', '160'],
                ['NB-GRND-250', '7003', 'Nabea Ground Coffee 250g', 'BEV-COF', '6.2500', '3.9000', '12', '80', '320'],
                ['NB-GRND-500', '7004', 'Nabea Ground Coffee 500g', 'BEV-COF', '11.5000', '7.2000', '12', '50', '200'],
                ['NB-ESP-250', '7005', 'Nabea Espresso Roast 250g', 'BEV-COF', '7.2500', '4.5000', '12', '70', '280'],
                ['NB-CARD-200', '7006', 'Nabea Cardamom Coffee 200g', 'BEV-COF', '5.7500', '3.5000', '12', '80', '320'],
                ['NB-TURK-200', '7007', 'Nabea Turkish Coffee 200g', 'BEV-COF', '4.7500', '2.9000', '12', '90', '360'],
                ['NB-INST-100', '7008', 'Nabea Instant Coffee 100g', 'BEV-COF', '4.2500', '2.6000', '12', '70', '280'],
                ['NB-INST-200', '7009', 'Nabea Instant Coffee 200g', 'BEV-COF', '7.5000', '4.6000', '12', '50', '200'],
                ['NB-DECAF-250', '7010', 'Nabea Decaf Beans 250g', 'BEV-COF', '7.5000', '4.7000', '12', '40', '160'],
                ['NB-DRIP', '7011', 'Nabea Drip Coffee Bag', 'BEV-COF', '0.6500', '0.3200', '50', '200', '800'],
                ['NB-TEA-BLK-100', '7012', 'Nabea Black Tea 100s', 'BEV-TEA', '4.2500', '2.6000', '12', '70', '280'],
                ['NB-TEA-BLK-25', '7013', 'Nabea Black Tea 25s', 'BEV-TEA', '1.4500', '0.8500', '24', '100', '400'],
                ['NB-TEA-GRN-25', '7014', 'Nabea Green Tea 25s', 'BEV-TEA', '1.6500', '0.9500', '24', '80', '320'],
                ['NB-TEA-MINT-20', '7015', 'Nabea Mint Tea 20s', 'BEV-TEA', '1.5500', '0.9000', '24', '80', '320'],
                ['NB-TEA-CHAM-20', '7016', 'Nabea Chamomile Tea 20s', 'BEV-TEA', '1.7500', '1.0000', '24', '60', '240'],
                ['NB-TEA-ZHO-20', '7017', 'Nabea Zhourat 20s', 'BEV-TEA', '1.8500', '1.0500', '24', '70', '280'],
                ['NB-TEA-ANISE', '7018', 'Nabea Anise Tea 20s', 'BEV-TEA', '1.5500', '0.9000', '24', '60', '240'],
                ['NB-COCOA-250', '7019', 'Nabea Cocoa Powder 250g', 'GRO-COC', '4.5000', '2.8000', '12', '50', '200'],
                ['NB-CHOC-400', '7020', 'Nabea Drinking Chocolate 400g', 'GRO-COC', '5.2500', '3.2000', '12', '40', '160'],
                ['NB-SYRUP-VAN', '7021', 'Nabea Vanilla Syrup 750ml', 'GRO-COC', '6.7500', '4.1000', '6', '36', '144'],
                ['NB-SYRUP-CAR', '7022', 'Nabea Caramel Syrup 750ml', 'GRO-COC', '6.7500', '4.1000', '6', '36', '144'],
            ],
            'packaging' => ['NB-BAG', '7090', 'Coffee Valve Bag 250g', 'COFFEEBAG', 'PKG', 'coffeebag', '0.2200', '0.0900', '200', '400', '1600', 'Valve coffee bag. Purchase unit is a case of 200.'],
            'produce' => ['NB-DRIP-8', '7080', 'Nabea Drip Coffee 8 Pack', 'BEV-COF', '4.8000', '2.5600', 'Pack of eight Nabea drip bags. Built from NB-DRIP.', 'NB-DRIP', '8'],
            'bundle' => ['NB-MORNING', '7081', 'Nabea Morning Set', 'BEV', '14.5000', '9.2000', 'Ground coffee, black tea, and zhourat.', ['NB-GRND-250' => '1', 'NB-TEA-BLK-25' => '1', 'NB-TEA-ZHO-20' => '1']],
            'customers' => [
                ['CUST-GEMCAFE', 'Gemmayze Coffee Bar', 'gemmayze.coffee@gmail.com', '+961 1 683 100', 'horeca', 'bank', 'net30', '601-870101', 'Coffee bar. Not a tenant on this system.', 'Gouraud Street', 'Beirut', 'Beirut', 'Gemmayze Coffee Buyer'],
                ['CUST-HAMROAST', 'Hamra Office Pantry', 'hamra.pantry@gmail.com', '+961 1 683 110', 'key', 'bank', 'net30', '601-870102', 'Office pantry. Not a tenant on this system.', 'Hamra Street', 'Beirut', 'Beirut', 'Hamra Pantry Buyer'],
                ['CUST-MARCAFE', 'Mar Mikhael Cafe', 'marmikhael.cafe@gmail.com', '+961 1 683 120', 'horeca', 'card', 'due', '601-870103', 'Cafe. Not a tenant on this system.', 'Armenia Street', 'Beirut', 'Beirut', 'Mar Mikhael Buyer'],
                ['CUST-BROUMANA', 'Broummana Hotel Lounge', 'broummana.lounge@gmail.com', '+961 4 683 130', 'horeca', 'bank', 'net30', '601-870104', 'Hotel lounge. Not a tenant on this system.', 'Main Road', 'Broummana', 'Mount Lebanon', 'Broummana Lounge Buyer'],
                ['CUST-TRIPCAFE2', 'Tripoli Old Cafe', 'tripoli.oldcafe@gmail.com', '+961 6 683 140', 'horeca', 'cash', 'due', '601-870105', 'Cafe. Not a tenant on this system.', 'Old Souk', 'Tripoli', 'North Lebanon', 'Tripoli Old Cafe Owner'],
                ['CUST-ZAHLETEA', 'Zahle Tea House', 'zahle.teahouse@gmail.com', '+961 8 683 150', 'retail', 'card', 'due', '601-870106', 'Tea house. Not a tenant on this system.', 'Berdawni', 'Zahle', 'Bekaa', 'Zahle Tea House Buyer'],
                ['CUST-SAIDATEA', 'Saida Spice and Tea', 'saida.tea@gmail.com', '+961 7 683 160', 'retail', 'cash', 'due', '601-870107', 'Spice shop. Not a tenant on this system.', 'Old Souk', 'Saida', 'South Lebanon', 'Saida Tea Buyer'],
                ['CUST-COFWH', 'Metn Coffee Wholesale', 'metn.coffee@gmail.com', '+961 4 683 170', 'wholesale', 'bank', 'net30', '601-870108', 'Coffee wholesaler. Not a tenant on this system.', 'Dora Highway', 'Dora', 'Mount Lebanon', 'Metn Coffee Buyer'],
            ],
            'supplier' => ['SUP-COFFEEBAG', 'Gemmayze Bag Co', 'Gemmayze Bag Co SAL', 'gemmayze.bags@gmail.com', '+961 1 683 400', 'pkg', '601-870201', 'Coffee bag supplier. Not a tenant on this system.', 'Industrial Street', 'Bourj Hammoud', 'Mount Lebanon', 'Gemmayze Bag Sales', 'coffeebag'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pantry(): array
    {
        return [
            'owner' => 'ghadeer',
            'brand' => 'GHADEER',
            'barcode_prefix' => '535',
            'freight_plu' => '8098',
            'categories' => self::tree('GRO', 'Pantry', '#7CB342', 'Oil, cans, and dry goods from Ghadeer.', [
                ['code' => 'GRO-OIL', 'name' => 'Oil', 'color' => '#F9A825', 'description' => 'Cooking oil, olive oil, and tahini.'],
                ['code' => 'GRO-CAN', 'name' => 'Canned goods', 'color' => '#EF6C00', 'description' => 'Tomato paste, tuna, and beans.'],
                ['code' => 'GRO-DRY', 'name' => 'Dry goods', 'color' => '#8D6E63', 'description' => 'Rice, sugar, flour, and pulses.'],
            ], 'Tins used to pack pantry goods.'),
            'brands' => ['GHADEER' => 'Ghadeer', 'TINPACK' => 'Koura Tin Works'],
            'lines' => [
                ['GD-OIL-SUN-1L', '8001', 'Ghadeer Sunflower Oil 1L', 'GRO-OIL', '3.2500', '2.1000', '12', '100', '400'],
                ['GD-OIL-SUN-2L', '8002', 'Ghadeer Sunflower Oil 2L', 'GRO-OIL', '5.7500', '3.7000', '6', '60', '240'],
                ['GD-OIL-SUN-5L', '8003', 'Ghadeer Sunflower Oil 5L', 'GRO-OIL', '12.5000', '8.2000', '4', '30', '120'],
                ['GD-OIL-OLV-500', '8004', 'Ghadeer Olive Oil 500ml', 'GRO-OIL', '6.5000', '4.2000', '12', '50', '200'],
                ['GD-OIL-OLV-1L', '8005', 'Ghadeer Olive Oil 1L', 'GRO-OIL', '11.5000', '7.4000', '12', '40', '160'],
                ['GD-OIL-CORN', '8006', 'Ghadeer Corn Oil 1L', 'GRO-OIL', '3.4500', '2.2000', '12', '70', '280'],
                ['GD-TAHINI-400', '8007', 'Ghadeer Tahini 400g', 'GRO-OIL', '3.7500', '2.3000', '12', '60', '240'],
                ['GD-TAHINI-800', '8008', 'Ghadeer Tahini 800g', 'GRO-OIL', '6.7500', '4.2000', '6', '40', '160'],
                ['GD-TOM-400', '8009', 'Ghadeer Tomato Paste 400g', 'GRO-CAN', '1.0500', '0.6200', '12', '120', '480'],
                ['GD-TOM-800', '8010', 'Ghadeer Tomato Paste 800g', 'GRO-CAN', '1.8500', '1.1000', '12', '80', '320'],
                ['GD-TUNA-160', '8011', 'Ghadeer Tuna in Oil 160g', 'GRO-CAN', '1.8500', '1.1500', '24', '140', '560'],
                ['GD-TUNA-185', '8012', 'Ghadeer Tuna in Water 185g', 'GRO-CAN', '1.9500', '1.2000', '24', '100', '400'],
                ['GD-BEANS-400', '8013', 'Ghadeer White Beans 400g', 'GRO-CAN', '1.1500', '0.6800', '12', '90', '360'],
                ['GD-CHICK-400', '8014', 'Ghadeer Chickpeas 400g', 'GRO-CAN', '1.1500', '0.6800', '12', '90', '360'],
                ['GD-FOUL-400', '8015', 'Ghadeer Foul Medames 400g', 'GRO-CAN', '1.0500', '0.6200', '12', '80', '320'],
                ['GD-RICE-1KG', '8016', 'Ghadeer Egyptian Rice 1kg', 'GRO-DRY', '1.9500', '1.2000', '10', '160', '640'],
                ['GD-RICE-5KG', '8017', 'Ghadeer Egyptian Rice 5kg', 'GRO-DRY', '8.5000', '5.4000', '4', '50', '200'],
                ['GD-SUGAR-1KG', '8018', 'Ghadeer White Sugar 1kg', 'GRO-DRY', '1.3500', '0.8500', '10', '160', '640'],
                ['GD-FLOUR-1KG', '8019', 'Ghadeer All Purpose Flour 1kg', 'GRO-DRY', '1.1500', '0.7000', '10', '140', '560'],
                ['GD-BURG-1KG', '8020', 'Ghadeer Burghul 1kg', 'GRO-DRY', '1.6500', '0.9800', '10', '80', '320'],
                ['GD-LENT-1KG', '8021', 'Ghadeer Brown Lentils 1kg', 'GRO-DRY', '1.7500', '1.0500', '10', '80', '320'],
                ['GD-CHICKD-1KG', '8022', 'Ghadeer Dry Chickpeas 1kg', 'GRO-DRY', '1.9500', '1.1500', '10', '70', '280'],
                ['GD-SEM-1KG', '8023', 'Ghadeer Semolina 1kg', 'GRO-DRY', '1.4500', '0.8500', '10', '70', '280'],
                ['GD-SALT-750', '8024', 'Ghadeer Table Salt 750g', 'GRO-DRY', '0.6500', '0.2800', '12', '100', '400'],
            ],
            'packaging' => ['GD-TIN', '8090', 'Empty Tin 400g', 'TINPACK', 'PKG', 'tinpack', '0.1500', '0.0700', '100', '400', '1600', 'Empty 400g tin. Purchase unit is a case of 100.'],
            'produce' => ['GD-OIL-3', '8080', 'Ghadeer Sunflower Oil 3 Pack', 'GRO-OIL', '9.2500', '6.3000', 'Pack of three Ghadeer sunflower oil 1L bottles. Built from GD-OIL-SUN-1L.', 'GD-OIL-SUN-1L', '3'],
            'bundle' => ['GD-KITCHEN', '8081', 'Ghadeer Kitchen Box', 'GRO', '11.5000', '7.2000', 'Sunflower oil, rice, tomato paste, and tahini.', ['GD-OIL-SUN-1L' => '1', 'GD-RICE-1KG' => '1', 'GD-TOM-400' => '1', 'GD-TAHINI-400' => '1']],
            'customers' => [
                ['CUST-KOURAGRO', 'Koura Village Grocery', 'koura.grocery@gmail.com', '+961 6 684 100', 'retail', 'cash', 'due', '601-880101', 'Village grocery. Not a tenant on this system.', 'Main Road', 'Amioun', 'North Lebanon', 'Koura Grocery Owner'],
                ['CUST-TRIPGRO', 'Tripoli Souk Grocer', 'tripoli.grocer@gmail.com', '+961 6 684 110', 'retail', 'cash', 'due', '601-880102', 'Souk grocer. Not a tenant on this system.', 'Old Souk', 'Tripoli', 'North Lebanon', 'Tripoli Grocer Owner'],
                ['CUST-ZAHLEGRO', 'Zahle Provision Store', 'zahle.provision@gmail.com', '+961 8 684 120', 'wholesale', 'bank', 'net30', '601-880103', 'Provision wholesaler. Not a tenant on this system.', 'Boulevard', 'Zahle', 'Bekaa', 'Zahle Provision Buyer'],
                ['CUST-SAIDAGRO', 'Saida Provision Store', 'saida.provision@gmail.com', '+961 7 684 130', 'wholesale', 'bank', 'net30', '601-880104', 'Provision store. Not a tenant on this system.', 'Port Road', 'Saida', 'South Lebanon', 'Saida Provision Buyer'],
                ['CUST-NABGRO', 'Nabatieh Family Grocer', 'nabatieh.grocer@gmail.com', '+961 7 684 140', 'retail', 'cash', 'due', '601-880105', 'Family grocer. Not a tenant on this system.', 'Main Street', 'Nabatieh', 'Nabatieh', 'Nabatieh Grocer Owner'],
                ['CUST-RESTAUR', 'Batroun Kitchen', 'batroun.kitchen@gmail.com', '+961 6 684 150', 'horeca', 'bank', 'net30', '601-880106', 'Restaurant kitchen. Not a tenant on this system.', 'Harbor', 'Batroun', 'North Lebanon', 'Batroun Kitchen Buyer'],
                ['CUST-CATER', 'Beirut Catering Co', 'beirut.catering@gmail.com', '+961 1 684 160', 'key', 'bank', 'net30', '601-880107', 'Catering company. Not a tenant on this system.', 'Corniche El Mazraa', 'Beirut', 'Beirut', 'Beirut Catering Buyer'],
                ['CUST-ZOUKGRO', 'Zouk Neighborhood Grocer', 'zouk.grocer@gmail.com', '+961 9 684 170', 'retail', 'card', 'due', '601-880108', 'Neighborhood grocer. Not a tenant on this system.', 'Old Souk', 'Zouk Mikael', 'Mount Lebanon', 'Zouk Grocer Owner'],
            ],
            'supplier' => ['SUP-TINPACK', 'Koura Tin Works', 'Koura Tin Works SAL', 'koura.tins@gmail.com', '+961 6 684 400', 'pkg', '601-880201', 'Tin supplier. Not a tenant on this system.', 'Industrial Road', 'Amioun', 'North Lebanon', 'Koura Tin Works Sales', 'tinpack'],
        ];
    }
}
