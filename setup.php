<?php
/**
 * Builds the Plush (Karen, Nairobi) booking store inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Service photos are read from /wordpress/pl-images.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized, so each one is copied straight into uploads and registered with its
// size. (media_handle_sideload opens every image, which made the demo slow to build.)
function pl_sideload( $tmp, $name, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], $name );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) {
		return new WP_Error( 'pl_copy', 'Could not copy ' . $name );
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

// One transaction for the whole import: SQLite otherwise commits (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

// Fast demo build: the photos are already web-sized, so skip making thumbnails of each one.
if ( defined( 'PL_FAST' ) && PL_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

// Store settings.
foreach ( [
	'blogname'                               => 'Plush',
	'blogdescription'                        => 'Automotive Care Perfection. Award-winning detailing, paint work, vinyl wraps and paint protection in Karen, Nairobi.',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_address'              => 'Karen',
	'woocommerce_store_city'                 => 'Nairobi',
	'woocommerce_manage_stock'               => 'no',
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'KE' ],
	'woocommerce_ship_to_countries'          => 'disabled',
	'woocommerce_enable_reviews'             => 'yes',
	'woocommerce_review_rating_verification_label' => 'yes',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_checkout_company_field'     => 'hidden',
	'woocommerce_checkout_address_2_field'   => 'hidden',
	'woocommerce_enable_coupons'             => 'yes',
	'woocommerce_calc_discounts_sequentially' => 'no',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// Service categories (order = order on the home page, in the menu and in the filters). The service list
// is Plush's own: their studio signboard and the service list post on Instagram (Sep 2023).
$cat = [];
$i   = 0;
foreach ( [
	'ida-detailing'            => [ 'IDA-Certified Detailing', 'Extensive interior and exterior detailing to International Detailing Association standards: decontamination, deep interior restoration and ozone odour removal.' ],
	'paint-correction-ceramic' => [ 'Paint Correction & Ceramic', 'Multi-stage machine paint correction with Rupes equipment, sealed under a Gyeon ceramic coating.' ],
	'vinyl-wraps'              => [ 'Vinyl Wraps & Branding', 'Colour-change wraps in gloss, satin, matte and colour-shift finishes, chrome deletes and accents, and commercial vehicle branding.' ],
	'body-shop'                => [ 'Body Shop & Paint Work', 'Accident and fender-bender repair, dent removal, panel repaints, full resprays, rim refurbishing and facelifts.' ],
	'ppf-tint'                 => [ 'PPF & Window Tint', 'Self-healing paint protection film with a 10-year warranty, and Llumar IRX ceramic tint with a lifetime warranty.' ],
	'engine-steam'             => [ 'Steamed Engine Detailing', 'Safe steam degreasing of the engine bay, finished with a protective dressing.' ],
	'add-ons'                  => [ 'Add-ons & Restoration', 'Headlights, leather, black trims and odour removal: add them to any booking.' ],
] as $slug => [ $name, $desc ] ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'description' => $desc ] );
	$cat[ $slug ] = (int) $t['term_id'];
	update_term_meta( $cat[ $slug ], 'order', $i++ );
}

// Vehicle class: a global attribute with its own pages (/vehicle/executive-suv/).
if ( ! wc_attribute_taxonomy_id_by_name( 'vehicle' ) ) {
	wc_create_attribute( [ 'name' => 'Vehicle class', 'slug' => 'vehicle', 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => true ] );
}
if ( ! taxonomy_exists( 'pa_vehicle' ) ) {
	register_taxonomy( 'pa_vehicle', [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ] );
}
$sizes = [
	'saloon'        => [ 'Saloon / hatchback', 'Saloons and hatchbacks: Golf GTI, Jetta, Mercedes C- and E-Class, BMW 3 Series, Jaguar XF.' ],
	'compact-suv'   => [ 'Compact SUV', 'Compact SUVs and crossovers: RAV4, BMW X3, Audi Q5, Porsche Macan, Mercedes GLC.' ],
	'executive-suv' => [ 'Executive SUV / 4x4', 'Big SUVs and 4x4s: Land Cruiser Prado & V8, Range Rover, Defender, G-Class, BMW X5.' ],
	'exotic'        => [ 'Performance & exotic', 'M, AMG and RS models, Porsche, supercars and luxury imports.' ],
];
$size = [];
foreach ( $sizes as $slug => [ $name, $desc ] ) {
	$t = term_exists( $slug, 'pa_vehicle' ) ?: wp_insert_term( $name, 'pa_vehicle', [ 'slug' => $slug, 'description' => $desc ] );
	$size[ $slug ] = (int) $t['term_id'];
	update_term_meta( $size[ $slug ], 'order', count( $size ) );
}
delete_transient( 'wc_attribute_taxonomies' );

// Payment: nothing is paid online. The M-Pesa till isn't public, so it is sent on confirmation (PLACEHOLDER).
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Pay at the studio when you collect (M-Pesa or cash)',
	'description'        => 'Inspect your car when it\'s ready, then pay at our Karen studio by M-Pesa or cash.',
	'instructions'       => 'We will call to confirm your booking. Pay by M-Pesa or cash when you collect your car.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );
update_option( 'woocommerce_bacs_settings', [
	'enabled'         => 'yes',
	'title'           => 'M-Pesa deposit to hold my booking',
	'description'     => 'For wraps, PPF, paint work and ceramic coatings. We send our M-Pesa details when we call to confirm; the deposit comes off your total.',
	'instructions'    => 'We will call to confirm your booking and send our M-Pesa details for the deposit. The balance is paid when you collect your car.',
	'account_details' => '',
] );
update_option( 'woocommerce_bacs_accounts', [] );
update_option( 'woocommerce_cheque_settings', [ 'enabled' => 'no' ] );
update_option( 'woocommerce_gateway_order', [ 'cod' => 0, 'bacs' => 1 ] );

// Services. Every price is a PLACEHOLDER (Nairobi market estimate) to confirm with Plush: they publish none.
// The facts (Rupes, Gyeon, Llumar IRX lifetime warranty, 7.5 mil self-healing PPF with a 10-year warranty,
// ozone generators, steam engine wash) are from their own Instagram captions and stories.
// 'by_size' => [ saloon, compact SUV, executive SUV, exotic ] prices; 'attr2' + 'options2' => [ label => multiplier ]
// adds a second choice. 'options' => [ label => price ] is a choice that doesn't depend on the vehicle.
// 'quote' => true: an indicative price, confirmed after Plush inspects the car.
$studio = 'Our studio in Karen, Nairobi (or VIP pick-up)';
$products = [
	[
		'slug' => 'full-detail', 'name' => 'Signature Full Detail', 'cats' => [ 'ida-detailing' ],
		'badge' => 'Most booked', 'perk' => 'IDA-standard detail', 'perks' => 'Interior & exterior | Decontamination | Hand-applied sealant', 'time' => '1 day in the studio',
		'clip' => 'polish',
		'short' => 'Our extensive interior and exterior detail, done to International Detailing Association standards: foam pre-wash, hand wash, iron and tar decontamination, wheels and arches, steam-cleaned cabin, leather care and a sealant.',
		'desc' => 'The detail that put Plush on the map. Outside, the car gets a snow-foam pre-wash, a two-bucket hand wash, iron and tar decontamination, wheels, tyres and arches deep-cleaned, black trims restored and a hand-applied sealant. Inside, we vacuum and steam-clean every surface, shampoo and extract the carpets, clean and condition the leather, detail the dashboard, vents and door cards, and finish the glass inside and out. Book it on its own, or as the first step before paint correction, ceramic coating or PPF.',
		'by_size' => [ 12000, 15000, 18000, 22000 ],
		'included' => [ 'Snow-foam pre-wash and two-bucket hand wash', 'Iron fallout and tar decontamination', 'Wheels, tyres and arches deep-cleaned', 'Black trims cleaned, conditioned and coated', 'Hand-applied paint sealant', 'Interior vacuum and steam clean', 'Carpet and mat shampoo with extraction', 'Leather cleaned and conditioned', 'Dashboard, vents and door cards detailed', 'Glass inside and out' ],
		'specs' => [ 'Time' => 'About a day; drop off in the morning', 'Where' => $studio, 'Standard' => 'International Detailing Association methods', 'Payment' => 'When you collect, M-Pesa or cash' ],
		'upsells' => [ 'steamed-engine-bay', 'headlight-restoration', 'ozone-odour-removal' ],
	],
	[
		'slug' => 'interior-detail-ozone', 'name' => 'Deep Interior Restoration & Ozone', 'cats' => [ 'ida-detailing' ],
		'badge' => 'Interior', 'perk' => 'Ozone treated', 'perks' => 'Seats out where needed | Steam & extraction | Ozone odour removal', 'time' => '1 day in the studio',
		'short' => 'For cabins that need more than a clean: seats and carpets shampooed and extracted, every surface steam-cleaned, leather fed, and an ozone treatment that kills smoke, damp and food smells at the source.',
		'desc' => 'We restore interiors one car at a time. Door cards, consoles, seats and headliner are cleaned with steam and agitation, carpets and seats are shampooed and extracted (seats come out where it helps), leather is cleaned and conditioned, and the cabin finishes with an ozone generator treatment to remove bad odours like smoke and dampness, and to disinfect the car.',
		'by_size' => [ 9000, 11000, 13000, 16000 ],
		'included' => [ 'Full vacuum, including under the seats', 'Steam cleaning of every hard surface', 'Seat and carpet shampoo with extraction', 'Headliner cleaned', 'Leather cleaned and conditioned', 'Ozone generator odour treatment and disinfection' ],
		'specs' => [ 'Time' => 'About a day', 'Where' => $studio, 'Drying' => 'Dry and ready when you collect', 'Payment' => 'When you collect, M-Pesa or cash' ],
		'upsells' => [ 'leather-deep-clean', 'steamed-engine-bay' ],
	],
	[
		'slug' => 'exterior-detail-decon', 'name' => 'Exterior Detail & Decontamination', 'cats' => [ 'ida-detailing' ],
		'badge' => 'Exterior', 'perk' => 'Clay & iron decon', 'perks' => 'Snow foam | Clay bar & iron removal | Trims coated', 'time' => 'Half a day',
		'short' => 'The outside of our signature detail on its own: snow foam, hand wash, clay bar and iron decontamination, watermark removal, wheels and arches, black trims coated and a sealant.',
		'desc' => 'A deep clean that a car wash can\'t reach. After the foam and hand wash, we clay the paint and dissolve iron fallout, remove watermarks, deep-clean wheels and arches, clean, condition and coat the exterior black plastics, and seal the paint so water beads off.',
		'by_size' => [ 6000, 7500, 9000, 11000 ],
		'included' => [ 'Snow-foam pre-wash and hand wash', 'Clay bar and iron decontamination', 'Watermark removal', 'Wheels, tyres and arches', 'Exterior black plastics cleaned, conditioned and coated', 'Paint sealant' ],
		'specs' => [ 'Time' => 'About half a day', 'Where' => $studio, 'Best with' => 'Paint correction or ceramic', 'Payment' => 'When you collect, M-Pesa or cash' ],
		'upsells' => [ 'steamed-engine-bay', 'headlight-restoration', 'black-trim-restoration' ],
	],
	[
		'slug' => 'paint-correction', 'name' => 'Rupes Paint Correction', 'cats' => [ 'paint-correction-ceramic' ],
		'badge' => 'Paint', 'perk' => 'Swirls & scratches out', 'perks' => 'Paint depth measured | Rupes machine polishing | Mirror gloss', 'time' => '1–3 days',
		'clip' => 'polish',
		'short' => 'Multi-stage machine polishing with Rupes equipment that removes swirls, watermarks, oxidation and light scratches. We measure the paint first, so we only take off what the clear coat can spare.',
		'desc' => 'Our process, as on our Instagram: the car is washed and clayed, iron fallout removed, then we measure the paint with a gauge, because some factory clear coats are very thin. We choose the pad and compound for your paint and work panel by panel: compounding, then polishing (and wet or dry sanding for deeper defects). A one-stage enhancement brings back gloss; a two-stage correction takes out most swirls and light scratches. Seal it with a ceramic coating to keep it.',
		'by_size' => [ 20000, 25000, 30000, 38000 ], 'attr2' => 'Correction', 'options2' => [ '1-stage enhancement' => 1, '2-stage correction' => 1.6 ],
		'included' => [ 'Foam bath, hand wash and clay bar', 'Iron fallout removal', 'Paint depth measured on every panel', 'Compounding and polishing with Rupes machines', 'Wet or dry sanding of deeper defects (2-stage)', 'Panel wipe and inspection under lights' ],
		'specs' => [ 'Time' => '1–3 days, depending on the stage', 'Where' => 'Indoors at our Karen studio', 'Equipment' => 'Rupes polishers, pads and compounds', 'Payment' => 'Deposit to book, balance on collection' ],
		'upsells' => [ 'ceramic-coating', 'headlight-restoration' ],
	],
	[
		'slug' => 'ceramic-coating', 'name' => 'Gyeon Ceramic Coating', 'cats' => [ 'paint-correction-ceramic' ],
		'badge' => 'Protection', 'perk' => 'Hydrophobic gloss', 'perks' => 'Gyeon ceramic | Body, rims & interior | Water beads off', 'time' => '2–3 days',
		'short' => 'A hard, glossy Gyeon ceramic layer bonded to the paint: water and dirt slide off, washing is quicker, and the paint is protected from sun, bird droppings and tree sap. Body, rims and interior surfaces can all be coated.',
		'desc' => 'We wash, decontaminate and polish the paint first (the coating locks in whatever is underneath), then apply Gyeon ceramic coating panel by panel and let it cure indoors. Choose how long you want the protection to last; longer protection means more layers and a more durable coating. We can coat the rims and interior surfaces too: ask us.',
		'by_size' => [ 35000, 42000, 50000, 65000 ], 'attr2' => 'Protection', 'options2' => [ '1 year' => 1, '3 years' => 1.6, '5 years' => 2.2 ],
		'included' => [ 'Foam bath, hand wash and clay bar', 'Iron fallout removal', 'One-stage machine polish', 'Panel wipe to remove polishing oils', 'Gyeon ceramic coating, panel by panel', 'Indoor curing and aftercare guide' ],
		'specs' => [ 'Time' => '2–3 days, including curing', 'Coating' => 'Gyeon ceramic', 'Aftercare' => 'No washing for 7 days; hand wash after that', 'Payment' => 'Deposit to book, balance on collection' ],
		'upsells' => [ 'paint-correction', 'rim-restoration', 'headlight-restoration' ],
	],
	[
		'slug' => 'colour-change-wrap', 'name' => 'Colour-Change Vinyl Wrap', 'cats' => [ 'vinyl-wraps' ],
		'badge' => 'Wrap', 'perk' => 'Any colour, any finish', 'perks' => 'Gloss, satin & matte | Colour-shift & iridescent | Protects the paint underneath', 'time' => '4–7 days', 'quote' => true,
		'clip' => 'wrap',
		'short' => 'A full colour change without a respray: gloss, satin, matte, colour-shift and iridescent films, fitted panel by panel in our studio. The factory paint underneath stays protected, and the wrap can come off later.',
		'desc' => 'From satin white to iridescent white on a Range Rover Sport, an E-Class in iridescent purple, or a holographic Prado: our wrap team strips trims, handles and badges where needed so edges tuck in cleanly, then wraps panel by panel with heat-set film. Bring a colour in mind or choose from our swatch books in the studio. The price here is indicative; we confirm it when we see the car and the film you choose.',
		'by_size' => [ 180000, 220000, 270000, 340000 ], 'attr2' => 'Finish', 'options2' => [ 'Gloss, satin or matte' => 1, 'Colour-shift or iridescent' => 1.3 ],
		'included' => [ 'Paint inspection and decontamination', 'Trims, handles and badges removed where needed', 'Film fitted panel by panel and heat-set', 'Door shuts on request', 'Aftercare guide' ],
		'specs' => [ 'Time' => 'About 4–7 days', 'Where' => 'Indoors at our Karen studio', 'Price' => 'Indicative: confirmed after we inspect the car', 'Payment' => 'Deposit to book, balance on collection' ],
		'upsells' => [ 'partial-wrap-accents', 'rim-restoration' ],
	],
	[
		'slug' => 'partial-wrap-accents', 'name' => 'Chrome Delete & Wrap Accents', 'cats' => [ 'vinyl-wraps' ],
		'badge' => 'Accents', 'perk' => 'Black-out styling', 'perks' => 'Chrome delete | Roof & bonnet | Mirrors & spoilers', 'time' => '1–2 days', 'quote' => true,
		'short' => 'Smaller wraps that change the look: chrome window trims blacked out, a contrast roof or carbon bonnet, mirror caps and spoilers.',
		'desc' => 'Black out the chrome, add a gloss-black roof to a white Range Rover, or a carbon-look bonnet. Each job is wrapped by the same team that does our full colour changes.',
		'options' => [ 'Chrome delete (window trims)' => 18000, 'Roof wrap' => 25000, 'Bonnet wrap' => 22000, 'Mirror caps & spoiler' => 12000 ], 'attr' => 'Accent',
		'included' => [ 'Panels cleaned and prepared', 'Film fitted and heat-set', 'Edges tucked where possible' ],
		'specs' => [ 'Time' => 'About 1–2 days', 'Where' => 'Indoors at our Karen studio', 'Price' => 'Indicative: big SUVs may cost more' ],
		'upsells' => [ 'colour-change-wrap' ],
	],
	[
		'slug' => 'commercial-branding', 'name' => 'Commercial Vehicle Branding', 'cats' => [ 'vinyl-wraps' ],
		'badge' => 'Branding', 'perk' => 'Fleet-ready', 'perks' => 'Logos & lettering | Half or full wraps | Fleets', 'time' => '1–3 days per vehicle', 'quote' => true,
		'short' => 'Your brand on the road: door logos and lettering, half wraps or full branded wraps for company cars, vans and fleets.',
		'desc' => 'Send us your logo and the vehicles, and we design the layout, print and fit it. For fleets, we schedule the vehicles so the business keeps running. The price here is indicative; we quote per vehicle after seeing the artwork.',
		'options' => [ 'Door logos & lettering' => 15000, 'Half wrap branding' => 90000, 'Full branded wrap' => 180000 ], 'attr' => 'Coverage',
		'included' => [ 'Layout on your vehicle', 'Print and lamination', 'Fitting in our studio' ],
		'specs' => [ 'Time' => 'About 1–3 days per vehicle', 'Artwork' => 'Send your logo in high resolution', 'Fleets' => 'Ask for a per-vehicle quote', 'Price' => 'Indicative: confirmed after we see the artwork' ],
		'upsells' => [],
	],
	[
		'slug' => 'collision-repair-repaint', 'name' => 'Accident Repair & Panel Repaint', 'cats' => [ 'body-shop' ],
		'badge' => 'Body shop', 'perk' => 'Colour-matched', 'perks' => 'Dent removal | Panel beating | Colour-matched repaint', 'time' => '2–5 days', 'quote' => true,
		'clip' => 'paint',
		'short' => 'Fender benders, scuffed bumpers and dented doors fixed and repainted to factory standard, colour-matched and blended so you can\'t tell which panel was done.',
		'desc' => 'We remove dents, panel-beat and fill where needed, prime and paint in our booth, then blend the colour into the next panels and polish so the repair disappears. Insurance job? Bring the assessment and we work to it. Prices here are per panel and indicative; we confirm after inspecting the damage.',
		'options' => [ '1 panel' => 15000, '2 panels' => 28000, '3 panels' => 40000, 'Bumper (front or rear)' => 18000 ], 'attr' => 'Panels',
		'included' => [ 'Damage assessment', 'Dent removal and panel beating', 'Filler, primer and sanding', 'Colour-matched paint and clear coat', 'Blending and polish' ],
		'specs' => [ 'Time' => 'About 2–5 days', 'Where' => 'Our Karen body shop', 'Insurance' => 'We work to your assessor\'s report', 'Price' => 'Indicative: confirmed after inspection' ],
		'upsells' => [ 'paint-correction', 'ceramic-coating' ],
	],
	[
		'slug' => 'full-respray', 'name' => 'Full Respray', 'cats' => [ 'body-shop' ],
		'badge' => 'Body shop', 'perk' => 'Factory-grade finish', 'perks' => 'Stripped & prepped | Booth-sprayed | Cut & polished', 'time' => '1–2 weeks', 'quote' => true,
		'short' => 'A full respray in the original colour or a new one, after a cheap respray elsewhere or years of sun: stripped, prepped, booth-sprayed and polished to a factory-grade finish.',
		'desc' => 'Many of our resprays fix a low-quality respray done elsewhere. We strip the old paint where needed, repair the panels, mask and prime, spray in our booth and finish with a cut and polish. The price here is indicative and depends on the car\'s condition; we confirm after inspection.',
		'by_size' => [ 120000, 150000, 190000, 260000 ],
		'included' => [ 'Inspection and paint plan', 'Strip and repair of panels', 'Masking, primer and sanding', 'Base coat and clear coat in the booth', 'Cut and polish' ],
		'specs' => [ 'Time' => 'About 1–2 weeks', 'Where' => 'Our Karen body shop', 'Colour' => 'Original or a new colour', 'Price' => 'Indicative: confirmed after inspection' ],
		'upsells' => [ 'ceramic-coating', 'rim-restoration' ],
	],
	[
		'slug' => 'rim-restoration', 'name' => 'Rim Refurbishment & Painting', 'cats' => [ 'body-shop' ],
		'badge' => 'Wheels', 'perk' => 'Kerb damage fixed', 'perks' => 'Kerb marks repaired | Painted any colour | Sealed', 'time' => '1–2 days',
		'short' => 'Kerbed, corroded or tired rims repaired and repainted: back to silver, or gloss black, gunmetal or any colour you like.',
		'desc' => 'We take the wheels off, strip and repair kerb damage, prime and paint them, then seal them so brake dust washes off. Change the colour at the same time, like the gloss-black rims on the wrapped E-Class.',
		'options' => [ 'Set of 4: refurbish in original colour' => 20000, 'Set of 4: new colour' => 24000 ], 'attr' => 'Job',
		'included' => [ 'Wheels removed and tyres protected', 'Kerb damage repaired', 'Primer, paint and lacquer', 'Sealed and refitted' ],
		'specs' => [ 'Time' => 'About 1–2 days', 'Covers' => 'All four wheels', 'Where' => 'Our Karen body shop' ],
		'upsells' => [ 'ceramic-coating' ],
	],
	[
		'slug' => 'paint-protection-film', 'name' => '7.5 mil Self-Healing PPF', 'cats' => [ 'ppf-tint' ],
		'badge' => 'PPF', 'perk' => '10-year warranty', 'perks' => 'Self-healing film | Hydrophobic top coat | 10-year warranty', 'time' => '2–5 days',
		'clip' => 'ppf',
		'short' => 'Our 7.5 mil self-healing paint protection film with a 10-year warranty: an invisible layer that takes the stone chips and scratches instead of your paint. Light scratches heal with heat, and its ceramic top coat makes water bead off.',
		'desc' => 'Fitted on cars like the X3 M Competition and the Defender in our posts. We decontaminate and correct the paint first, then fit the film panel by panel, wrapping the edges where we can so it stays out of sight. Front package covers the parts that take the most chips; full body covers everything.',
		'by_size' => [ 150000, 180000, 220000, 280000 ], 'attr2' => 'Coverage', 'options2' => [ 'Front package (bumper, bonnet, wings, mirrors)' => 1, 'Full body' => 2.4 ],
		'included' => [ 'Wash and decontamination', 'Paint correction of the covered panels', 'Film cut to your car', 'Fitting with wrapped edges where possible', '10-year warranty', 'Aftercare guide' ],
		'specs' => [ 'Time' => '2–5 days', 'Film' => '7.5 mil, self-healing, hydrophobic top coat', 'Warranty' => '10 years', 'Payment' => 'Deposit to book, balance on collection' ],
		'upsells' => [ 'window-tint', 'ceramic-coating', 'headlight-restoration' ],
	],
	[
		'slug' => 'window-tint', 'name' => 'Llumar IRX Ceramic Tint', 'cats' => [ 'ppf-tint' ],
		'badge' => 'Tint', 'perk' => 'Lifetime warranty', 'perks' => 'Llumar IRX | Heat & UV rejection | Lifetime warranty', 'time' => 'Half a day',
		'short' => 'Llumar IRX ceramic tint with a lifetime warranty: cooler cabin, UV protection and privacy, with clearer visibility from inside than cheap film. Old, bubbling tint is stripped first.',
		'desc' => 'We strip substandard tint, clean the glass and fit Llumar IRX, a ceramic film that rejects heat without making the glass hard to see through at night (see the before-and-after in our posts). We follow the NTSA rules on how dark the front windows and windscreen may be. Xpel film is also available: ask us.',
		'by_size' => [ 18000, 22000, 26000, 32000 ],
		'included' => [ 'Old tint removed if needed', 'Glass cleaned and prepared', 'Llumar IRX film cut to each window', 'Lifetime warranty', 'Aftercare advice' ],
		'specs' => [ 'Time' => 'About half a day', 'Film' => 'Llumar IRX ceramic (Xpel on request)', 'Warranty' => 'Lifetime', 'Aftercare' => 'Keep windows up for 3 days' ],
		'upsells' => [ 'paint-protection-film', 'exterior-detail-decon' ],
	],
	[
		'slug' => 'steamed-engine-bay', 'name' => 'Steamed Engine Bay Detail', 'cats' => [ 'engine-steam', 'add-ons' ],
		'badge' => 'Engine', 'perk' => 'Safe high-temp steam', 'perks' => 'Electrics protected | High-temperature steam | Anti-oxidant dressing', 'time' => '1–2 hours',
		'clip' => 'steam',
		'short' => 'High-temperature steam lifts oil and grime off the engine bay with far less water than a pressure wash, so it\'s safe around modern electronics. Finished with an anti-oxidant dressing on plastics and rubber.',
		'desc' => 'Another day, another clean engine. We cover the sensitive parts, steam and agitate the grime away, dry the bay and dress the plastics and hoses with an anti-oxidant coating so they don\'t fade or crack. A clean engine is easier to check for leaks and makes a real difference when you sell.',
		'by_size' => [ 3500, 4000, 5000, 6000 ],
		'included' => [ 'Sensitive parts and electrics covered', 'High-temperature steam and agitation', 'Bay dried', 'Anti-oxidant dressing on plastics and hoses' ],
		'specs' => [ 'Time' => 'About 1–2 hours', 'Method' => 'Steam, not a pressure washer', 'Best with' => 'Any detail', 'Payment' => 'When you collect, M-Pesa or cash' ],
		'upsells' => [ 'full-detail', 'headlight-restoration' ],
	],
	[
		'slug' => 'headlight-restoration', 'name' => 'Headlight Restoration & UV Protection', 'cats' => [ 'add-ons' ],
		'badge' => 'Add-on', 'perk' => 'Clear lenses', 'perks' => 'Removes yellowing | Brighter at night | UV sealed', 'time' => '+1–2 hours',
		'short' => 'Yellow, cloudy headlights wet-sanded and machine-polished clear, then sealed against UV so they stay clear and light the road properly again. Add headlight PPF for the best protection.',
		'desc' => 'Sun-faded headlights cut how far you can see at night and age the whole car. We mask around the lights, wet-sand the oxidised layer away, polish the lenses clear and seal them with a UV-protective coating. Ask about headlight PPF to keep them that way for years.',
		'price' => 5000,
		'included' => [ 'Masking around the lights', 'Wet-sanding of the oxidised layer', 'Machine polish to clear', 'UV-protective sealant' ],
		'specs' => [ 'Added time' => 'About 1–2 hours', 'Covers' => 'Both headlights' ],
	],
	[
		'slug' => 'ozone-odour-removal', 'name' => 'Ozone Odour Removal & Disinfection', 'cats' => [ 'add-ons' ],
		'badge' => 'Add-on', 'perk' => 'Kills smells at the source', 'perks' => 'Smoke & damp | Disinfection', 'time' => '+1 hour',
		'short' => 'An ozone generator treatment that removes smoke, damp and food smells at the source instead of covering them up, and disinfects the cabin.',
		'desc' => 'We use ozone generators to remove bad odours such as smoke and dampness. The cabin is sealed with the generator running, then aired out before you collect the car.',
		'price' => 3500,
		'included' => [ 'Ozone treatment of the closed cabin', 'Air-out before collection' ],
		'specs' => [ 'Added time' => 'About 1 hour', 'Best with' => 'Deep Interior Restoration' ],
	],
	[
		'slug' => 'leather-deep-clean', 'name' => 'Leather Deep Clean & Treatment', 'cats' => [ 'add-ons' ],
		'badge' => 'Add-on', 'perk' => 'Protects seats', 'perks' => 'Deep cleaned | Conditioned | Protected', 'time' => '+2 hours',
		'short' => 'Leather seats, steering wheel and trims deep-cleaned, then fed and protected so they stay soft and don\'t crack in the Nairobi sun.',
		'desc' => 'Light leather shows every mark. We deep-clean the grain with soft brushes, condition the leather and add a protective layer so spills and dye transfer wipe off.',
		'price' => 6000,
		'included' => [ 'Leather deep-cleaned with soft brushes', 'Conditioner worked in', 'Protective layer' ],
		'specs' => [ 'Added time' => 'About 2 hours', 'Covers' => 'Seats, steering wheel and leather trims' ],
	],
	[
		'slug' => 'black-trim-restoration', 'name' => 'Black Trim Restoration & Coating', 'cats' => [ 'add-ons' ],
		'badge' => 'Add-on', 'perk' => 'Faded trims back to black', 'perks' => 'Cleaned | Conditioned | Coated', 'time' => '+1 hour',
		'short' => 'Faded grey bumpers, arches and mirror bases cleaned, conditioned and coated back to a deep black that lasts.',
		'desc' => 'Exterior black plastics fade fast in the sun. We clean them deeply, condition them and apply a coating so they stay dark through washes and rain.',
		'price' => 4000,
		'included' => [ 'Exterior plastics deep-cleaned', 'Conditioned', 'Trim coating applied' ],
		'specs' => [ 'Added time' => 'About 1 hour', 'Covers' => 'Bumpers, arches, mirror bases and cowl' ],
	],
];

function pl_attach_images( $pid, $slug, $name ) {
	$num   = fn( $f ) => preg_match( '#^' . preg_quote( $slug, '#' ) . '(?:-(\d+))?\.webp$#', basename( $f ), $m ) ? (int) ( $m[1] ?? 1 ) : 0;
	$files = array_values( array_filter( glob( "/wordpress/pl-images/$slug*.webp" ) ?: [], fn( $f ) => $num( $f ) > 0 ) );
	usort( $files, fn( $a, $b ) => $num( $a ) <=> $num( $b ) );
	$ids = [];
	foreach ( $files as $file ) {
		$base = basename( $file );
		$tmp  = wp_tempnam( $base );
		copy( $file, $tmp );
		$n   = $num( $file );
		$att = pl_sideload( $tmp, $base, $pid, $name . ( $n > 1 ? ' – photo ' . $n : '' ) );
		if ( ! is_wp_error( $att ) ) $ids[ $n ] = $att;
	}
	return $ids; // photo number => attachment id
}

function pl_local_attr( $name, array $options, $position ) {
	$a = new WC_Product_Attribute();
	$a->set_name( $name );
	$a->set_options( $options );
	$a->set_position( $position );
	$a->set_visible( true );
	$a->set_variation( true );
	return $a;
}

$ids = [];
foreach ( $products as $order => $d ) {
	$by_size  = $d['by_size'] ?? null;
	$variable = $by_size || ! empty( $d['options'] );
	$p = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $order );
	$p->set_virtual( true );
	$p->set_description( $d['desc'] );
	$p->set_short_description( $d['short'] );
	$p->set_category_ids( array_map( fn( $c ) => $cat[ $c ], $d['cats'] ) );
	// The home page's packages: the headline service of each line, not the add-ons.
	$p->set_featured( in_array( $d['slug'], [ 'full-detail', 'paint-correction', 'ceramic-coating', 'colour-change-wrap', 'collision-repair-repaint', 'paint-protection-film', 'window-tint', 'steamed-engine-bay' ], true ) );
	foreach ( [ 'badge', 'perk', 'perks', 'time', 'clip' ] as $k ) {
		if ( ! empty( $d[ $k ] ) ) $p->update_meta_data( '_pl_' . $k, $d[ $k ] );
	}
	$p->update_meta_data( '_pl_specs', implode( "\n", array_map( fn( $k, $v ) => "$k: $v", array_keys( $d['specs'] ), $d['specs'] ) ) );
	$p->update_meta_data( '_pl_included', implode( "\n", $d['included'] ) );
	if ( ! empty( $d['quote'] ) ) $p->update_meta_data( '_pl_quote', 'yes' );

	$attrs = [];
	if ( $by_size ) {
		$a = new WC_Product_Attribute();
		$a->set_id( wc_attribute_taxonomy_id_by_name( 'vehicle' ) );
		$a->set_name( 'pa_vehicle' );
		$a->set_options( array_values( $size ) );
		$a->set_position( 0 );
		$a->set_visible( true );
		$a->set_variation( true );
		$attrs[] = $a;
		if ( ! empty( $d['options2'] ) ) $attrs[] = pl_local_attr( $d['attr2'], array_keys( $d['options2'] ), 1 );
	} elseif ( $variable ) {
		$attrs[] = pl_local_attr( $d['attr'], array_keys( $d['options'] ), 0 );
	}
	$p->set_attributes( $attrs );
	if ( $by_size ) {
		$defaults = [ 'pa_vehicle' => 'saloon' ];
		if ( ! empty( $d['options2'] ) ) $defaults[ sanitize_title( $d['attr2'] ) ] = array_key_first( $d['options2'] );
		$p->set_default_attributes( $defaults );
	} elseif ( $variable ) {
		$p->set_default_attributes( [ sanitize_title( $d['attr'] ) => array_key_first( $d['options'] ) ] );
	} else {
		$p->set_regular_price( $d['price'] );
	}
	$pid = $p->save();
	$ids[ $d['slug'] ] = $pid;

	$imgs = pl_attach_images( $pid, $d['slug'], $d['name'] );
	if ( $imgs ) {
		// Written as meta: a second full product save here slowed the import.
		set_post_thumbnail( $pid, $imgs[1] ?? reset( $imgs ) );
		update_post_meta( $pid, '_product_image_gallery', implode( ',', array_values( array_diff_key( $imgs, [ 1 => true ] ) ) ) );
	}

	if ( $variable ) {
		// One variation per combination: vehicle class × second choice, or the plain choices.
		$combos = [];
		if ( $by_size ) {
			foreach ( array_keys( $size ) as $n => $s ) {
				foreach ( $d['options2'] ?? [ null => 1 ] as $o2 => $mult ) {
					$va = [ 'pa_vehicle' => $s ];
					if ( $o2 !== '' && $o2 !== null ) $va[ sanitize_title( $d['attr2'] ) ] = $o2;
					$combos[] = [ $va, round( $by_size[ $n ] * $mult / 500 ) * 500 ];
				}
			}
		} else {
			foreach ( $d['options'] as $label => $price ) {
				$combos[] = [ [ sanitize_title( $d['attr'] ) => $label ], $price ];
			}
		}
		foreach ( $combos as $n => [ $va, $price ] ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( $va );
			$v->set_virtual( true );
			$v->set_regular_price( (string) $price );
			$v->set_menu_order( $n );
			$v->save();
		}
		WC_Product_Variable::sync( $pid );
	}
}

// "Popular add-ons" on each service page.
foreach ( $products as $d ) {
	if ( ! empty( $d['upsells'] ) ) {
		update_post_meta( $ids[ $d['slug'] ], '_upsell_ids', array_values( array_filter( array_map( fn( $s ) => $ids[ $s ] ?? 0, $d['upsells'] ) ) ) );
	}
}

// Pages.
function pl_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
pl_page( 'estimator', 'Build your package', "<!-- wp:paragraph -->\n<p>Choose your vehicle class, a package and any add-ons to see your price in KSh straight away. Book it in a few taps, or send it to us on WhatsApp for an exact quote.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[pl_estimator]\n<!-- /wp:shortcode -->" );
pl_page( 'fleet-commercial', 'Fleets, branding & commercial', "<!-- wp:paragraph -->\n<p>Company cars, executive fleets, vans and pickups: detailing, paint work, PPF and branding on a schedule that keeps your vehicles on the road. Tell us about your fleet and we reply on WhatsApp with a per-vehicle price.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[pl_fleet_form]\n<!-- /wp:shortcode -->" );

// Cart and checkout read as a booking.
foreach ( [ 'shop' => 'All services', 'cart' => 'Your booking', 'checkout' => 'Book your session' ] as $page => $title ) {
	$id = wc_get_page_id( $page );
	if ( $id > 0 ) wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
}

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
