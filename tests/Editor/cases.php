<?php
/**
 * Block specs for the editor validity check, serialized by Albert, printed as JSON.
 *
 * Run through `wp eval-file` with Albert active. Every case must serialize
 * without errors; block-validity.mjs then has the editor validate each one.
 *
 * @package Albert
 */

// phpcs:disable

use Albert\Blocks\BlockSerializer;

$supports = [
	'textColor'       => 'contrast',
	'backgroundColor' => 'base',
	'fontSize'        => 'large',
	'className'       => 'is-custom',
	'anchor'          => 'section-a',
	'style'           => [
		'spacing'    => [
			'padding' => [
				'top'    => 'var:preset|spacing|40',
				'bottom' => '2rem',
			],
			'margin'  => [ 'top' => '0' ],
		],
		'border'     => [
			'radius' => '8px',
			'width'  => '2px',
			'style'  => 'solid',
			'color'  => '#111111',
		],
		'typography' => [
			'lineHeight'    => '1.4',
			'letterSpacing' => '1px',
		],
		'elements'   => [ 'link' => [ 'color' => [ 'text' => 'var:preset|color|accent' ] ] ],
	],
];

$custom = [
	'style' => [
		'color'      => [
			'text'       => '#222222',
			'background' => '#f7cc26',
		],
		'typography' => [
			'fontSize'   => '48px',
			'fontWeight' => '700',
			'textAlign'  => 'center',
		],
	],
];

$paragraph = static fn ( string $text, array $attributes = [] ): array => [
	'name'       => 'core/paragraph',
	'attributes' => array_merge( [ 'content' => $text ], $attributes ),
];

$cases = [
	'paragraph'                  => [ $paragraph( 'Plain <strong>bold</strong> and <a href="https://example.com/">a link</a>.' ) ],
	'paragraph with supports'    => [ $paragraph( 'Styled', $supports + [ 'align' => 'wide' ] ) ],
	'paragraph with custom'      => [ $paragraph( 'Custom', $custom ) ],
	'paragraph drop cap and rtl' => [ $paragraph( 'Drop cap', [ 'dropCap' => true, 'direction' => 'rtl' ] ) ],
	'heading'                    => [
		[
			'name'       => 'core/heading',
			'attributes' => [ 'level' => 3, 'content' => 'Title' ],
		],
	],
	'heading with supports'      => [
		[
			'name'       => 'core/heading',
			'attributes' => array_merge( $supports, $custom, [ 'level' => 2, 'content' => 'Styled title' ] ),
		],
	],
	'list'                       => [
		[
			'name'        => 'core/list',
			'attributes'  => array_merge( $supports, [ 'ordered' => true, 'start' => 3, 'reversed' => true, 'type' => 'lower-alpha' ] ),
			'innerBlocks' => [
				[
					'name'        => 'core/list-item',
					'attributes'  => [ 'content' => 'One', 'textColor' => 'accent' ],
					'innerBlocks' => [
						[
							'name'        => 'core/list',
							'innerBlocks' => [
								[
									'name'       => 'core/list-item',
									'attributes' => [ 'content' => 'Nested' ],
								],
							],
						],
					],
				],
				[
					'name'       => 'core/list-item',
					'attributes' => [ 'content' => 'Two' ],
				],
			],
		],
	],
	'quote'                      => [
		[
			'name'        => 'core/quote',
			'attributes'  => array_merge( $supports, [ 'citation' => 'Someone <em>famous</em>' ] ),
			'innerBlocks' => [ $paragraph( 'Quoted' ) ],
		],
	],
	'image'                      => [
		[
			'name'       => 'core/image',
			'attributes' => [
				'id'              => 12,
				'url'             => 'https://example.com/a.jpg?x=1&y=2',
				'alt'             => 'A "quoted" alt',
				'caption'         => 'A <em>caption</em>',
				'sizeSlug'        => 'large',
				'linkDestination' => 'custom',
				'href'            => 'https://example.com/',
				'linkTarget'      => '_blank',
				'rel'             => 'noopener',
				'align'           => 'wide',
				'className'       => 'is-style-rounded',
				'anchor'          => 'figure-a',
				'width'           => '600px',
				'aspectRatio'     => '4/3',
				'scale'           => 'cover',
				'title'           => 'Title',
				'borderColor'     => 'accent',
				'lightbox'        => [ 'enabled' => true ],
				'style'           => [
					'border'  => [
						'radius' => '12px',
						'width'  => '3px',
					],
					'spacing' => [ 'margin' => [ 'bottom' => '1rem' ] ],
					'shadow'  => 'var:preset|shadow|natural',
				],
			],
		],
		[
			'name'       => 'core/image',
			'attributes' => [
				'url'          => 'https://example.com/b.jpg',
				'width'        => 300,
				'height'       => 200,
				'align'        => 'none',
				'isDecorative' => true,
			],
		],
	],
	'columns'                    => [
		[
			'name'        => 'core/columns',
			'attributes'  => [
				'className'         => 'project-card',
				'verticalAlignment' => 'center',
				'isStackedOnMobile' => false,
				'align'             => 'wide',
				'style'             => [
					'color'   => [ 'background' => '#f7cc26' ],
					'spacing' => [
						'padding'  => [ 'left' => '1rem' ],
						'blockGap' => [ 'left' => '2rem' ],
					],
				],
			],
			'innerBlocks' => [
				[
					'name'        => 'core/column',
					'attributes'  => [
						'width'             => '33.33%',
						'verticalAlignment' => 'top',
						'backgroundColor'   => 'base',
						'style'             => [ 'spacing' => [ 'padding' => [ 'top' => '1rem' ] ] ],
					],
					'innerBlocks' => [ $paragraph( 'Left' ) ],
				],
				[
					'name'        => 'core/column',
					'attributes'  => [ 'width' => '200px' ],
					'innerBlocks' => [ $paragraph( 'Right' ) ],
				],
			],
		],
	],
	'group'                      => [
		[
			'name'        => 'core/group',
			'attributes'  => array_merge(
				$supports,
				[
					'tagName'   => 'article',
					'align'     => 'full',
					'ariaLabel' => 'Project',
					'gradient'  => 'vivid-cyan-blue-to-vivid-purple',
					'layout'    => [ 'type' => 'constrained' ],
					'metadata'  => [ 'name' => 'Card' ],
				]
			),
			'innerBlocks' => [ $paragraph( 'Inside' ) ],
		],
		[
			'name'        => 'core/group',
			'attributes'  => [
				'style'  => [
					'dimensions' => [ 'minHeight' => '50vh' ],
					'shadow'     => 'var:preset|shadow|natural',
					'color'      => [ 'gradient' => 'linear-gradient(135deg,#fff 0%,#000 100%)' ],
				],
				'layout' => [ 'type' => 'flex', 'justifyContent' => 'center' ],
			],
			'innerBlocks' => [ $paragraph( 'Flex' ) ],
		],
	],
	'buttons'                    => [
		[
			'name'        => 'core/buttons',
			'attributes'  => [
				'fontSize' => 'small',
				'layout'   => [ 'type' => 'flex', 'justifyContent' => 'center' ],
			],
			'innerBlocks' => [
				[
					'name'       => 'core/button',
					'attributes' => [
						'text'            => 'Go <strong>now</strong>',
						'url'             => 'https://example.com/?a=1&b=2',
						'linkTarget'      => '_blank',
						'rel'             => 'noreferrer noopener',
						'title'           => 'Go',
						'backgroundColor' => 'accent',
						'textColor'       => 'base',
						'fontSize'        => 'large',
						'fontFamily'      => 'heading',
						'borderColor'     => 'contrast',
						'className'       => 'is-style-outline',
						'anchor'          => 'cta',
						'style'           => [
							'border'     => [
								'radius' => '0',
								'width'  => '2px',
							],
							'spacing'    => [
								'padding' => [
									'top'  => '1rem',
									'left' => 'var:preset|spacing|30',
								],
							],
							'typography' => [ 'letterSpacing' => '2px' ],
							'shadow'     => 'var:preset|shadow|natural',
							'dimensions' => [ 'width' => '50%' ],
						],
					],
				],
				[
					'name'       => 'core/button',
					'attributes' => [
						'text'    => 'Submit',
						'tagName' => 'button',
						'style'   => [
							'color'      => [
								'background' => '#000000',
								'text'       => '#ffffff',
							],
							'border'     => [ 'radius' => 0 ],
							'typography' => [ 'fontSize' => '20px' ],
						],
					],
				],
				[
					'name'       => 'core/button',
					'attributes' => [
						'text'     => 'Gradient',
						'url'      => 'https://example.com/',
						'gradient' => 'vivid-cyan-blue-to-vivid-purple',
					],
				],
			],
		],
	],
	'separator'                  => [
		[ 'name' => 'core/separator' ],
		[
			'name'       => 'core/separator',
			'attributes' => [
				'backgroundColor' => 'accent',
				'className'       => 'is-style-wide',
				'align'           => 'wide',
			],
		],
		[
			'name'       => 'core/separator',
			'attributes' => [
				'opacity' => 'css',
				'tagName' => 'div',
				'style'   => [
					'color'   => [ 'background' => '#ff0000' ],
					'spacing' => [ 'margin' => [ 'top' => '2rem' ] ],
				],
			],
		],
	],
	'spacer'                     => [
		[
			'name'       => 'core/spacer',
			'attributes' => [ 'height' => '40px' ],
		],
		[
			'name'       => 'core/spacer',
			'attributes' => [
				'height' => 'var:preset|spacing|50',
				'width'  => '20px',
				'style'  => [ 'spacing' => [ 'margin' => [ 'top' => '0' ] ] ],
			],
		],
	],
	'code'                       => [
		[
			'name'       => 'core/code',
			'attributes' => array_merge( $supports, [ 'content' => '<?php echo "a & b"; ?>' ] ),
		],
	],
	'pullquote'                  => [
		[
			'name'       => 'core/pullquote',
			'attributes' => array_merge(
				$supports,
				[
					'value'    => 'Big <em>idea</em>',
					'citation' => 'Someone',
					'align'    => 'wide',
					'style'    => array_merge( $supports['style'], [ 'typography' => [ 'textAlign' => 'left' ] ] ),
				]
			),
		],
	],
	'more supports'              => [
		$paragraph(
			'Gradient and font',
			[
				'gradient'   => 'vivid-cyan-blue-to-vivid-purple',
				'fontFamily' => 'heading',
				'style'      => [ 'typography' => [ 'fontSize' => '1.25rem', 'textAlign' => 'right', 'textTransform' => 'uppercase' ] ],
			]
		),
		[
			'name'       => 'core/heading',
			'attributes' => [
				'level'   => 4,
				'content' => 'Custom size',
				'style'   => [
					'typography' => [ 'fontSize' => 'clamp(1rem, 2vw, 2rem)' ],
					'color'      => [ 'gradient' => 'linear-gradient(90deg,#000 0%,#fff 100%)' ],
				],
			],
		],
		[
			'name'        => 'core/quote',
			'attributes'  => [ 'style' => [ 'typography' => [ 'textAlign' => 'center' ] ] ],
			'innerBlocks' => [ $paragraph( 'Centered' ) ],
		],
		[
			'name'       => 'core/image',
			'attributes' => [
				'url'        => 'https://example.com/c.jpg',
				'align'      => 'center',
				'scale'      => 'contain',
				'focalPoint' => [ 'x' => 0.25, 'y' => 0.75 ],
				'height'     => '200px',
				'style'      => [ 'border' => [ 'color' => '#ff0000', 'width' => '1px', 'style' => 'dashed' ] ],
			],
		],
		[
			'name'        => 'core/columns',
			'attributes'  => [
				'isStackedOnMobile' => true,
				'style'             => [ 'color' => [ 'gradient' => 'linear-gradient(90deg,#000 0%,#fff 100%)' ] ],
			],
			'innerBlocks' => [
				[
					'name'        => 'core/column',
					'attributes'  => [ 'width' => '66.66666666666666%' ],
					'innerBlocks' => [ $paragraph( 'Wide' ) ],
				],
				[
					'name'        => 'core/column',
					'innerBlocks' => [ $paragraph( 'Narrow' ) ],
				],
			],
		],
		[
			'name'        => 'core/buttons',
			'attributes'  => [ 'style' => [ 'typography' => [ 'fontSize' => '18px' ] ] ],
			'innerBlocks' => [
				[
					'name'       => 'core/button',
					'attributes' => [
						'text'  => 'Outline',
						'url'   => 'https://example.com/',
						'style' => [
							'border'     => [ 'color' => '#00ff00', 'radius' => '20px' ],
							'color'      => [ 'gradient' => 'linear-gradient(90deg,#000 0%,#fff 100%)' ],
							'typography' => [ 'textAlign' => 'left', 'fontWeight' => '600' ],
						],
					],
				],
			],
		],
		[
			'name'        => 'core/group',
			'attributes'  => [
				'style' => [
					'background' => [
						'backgroundImage' => [ 'url' => 'https://example.com/bg.jpg' ],
						'backgroundSize'  => 'cover',
					],
					'spacing'    => [ 'blockGap' => '3rem' ],
					'position'   => [ 'type' => 'sticky', 'top' => '0px' ],
				],
			],
			'innerBlocks' => [ $paragraph( 'Background' ) ],
		],
		[
			'name'       => 'core/spacer',
			'attributes' => [ 'width' => 'var:preset|spacing|30', 'height' => '10px' ],
		],
	],
	'html on a cover'            => [
		[
			'name'       => 'core/cover',
			'attributes' => [
				'customOverlayColor' => '#000000',
				'isUserOverlayColor' => true,
			],
			'html'       => '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-100 has-background-dim" style="background-color:#000000"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>On a cover</p><!-- /wp:paragraph --></div></div>',
		],
	],
	'html on a group'            => [
		[
			'name'       => 'core/group',
			'attributes' => [
				'tagName'   => 'article',
				'className' => 'project',
			],
			'html'       => '<article class="wp-block-group project"><!-- wp:paragraph --><p>Kept as sent</p><!-- /wp:paragraph --></article>',
		],
	],
];

$serializer = new BlockSerializer();
$out        = [];

foreach ( $cases as $label => $specs ) {
	$result = $serializer->serialize_with_issues( $specs );
	$out[]  = [
		'label'  => $label,
		'markup' => $result['markup'],
		'issues' => $result['issues'],
	];
}

// Block markup in `content` is kept as sent.
$content = '<!-- wp:group {"tagName":"article","className":"project","layout":{"type":"constrained"}} -->' . "\n"
	. '<article class="wp-block-group project"><!-- wp:columns {"style":{"color":{"background":"#f7cc26"}}} -->' . "\n"
	. '<div class="wp-block-columns has-background" style="background-color:#f7cc26"><!-- wp:column -->' . "\n"
	. '<div class="wp-block-column"><!-- wp:paragraph -->' . "\n" . '<p>Copied</p>' . "\n" . '<!-- /wp:paragraph --></div>' . "\n"
	. '<!-- /wp:column --></div>' . "\n" . '<!-- /wp:columns --></article>' . "\n" . '<!-- /wp:group -->';

$result = $serializer->serialize_with_issues( $content );
$out[]  = [
	'label'  => 'content kept as sent',
	'markup' => $result['markup'],
	'issues' => array_merge(
		$result['issues'],
		$result['markup'] === $content ? [] : [ [ 'severity' => 'error', 'message' => 'content was changed' ] ]
	),
];

$stray  = '<p>Stray HTML</p>' . "\n\n" . '<!-- wp:paragraph --><p>A block</p><!-- /wp:paragraph -->';
$result = $serializer->serialize_with_issues( $stray );
$out[]  = [
	'label'  => 'content with stray HTML',
	'markup' => $result['markup'],
	'issues' => $result['issues'],
];

echo wp_json_encode( $out );
