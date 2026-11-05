<?php
/**
 * Block Supports
 *
 * The HTML a block's supports (colour, typography, spacing, border, align,
 * anchor, className) put on its markup, and which attributes nobody writes.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.6.0
 */

namespace Albert\Blocks;

use WP_Block_Supports;

defined( 'ABSPATH' ) || exit;

/**
 * Builds wrapper and inner-element attributes from block supports.
 *
 * Wrapper classes come from WordPress's own supports engine, which reads the
 * live registry, so they follow each WordPress version and cover every support
 * a block declares. The wrapper's inline style is the style engine's CSS for
 * the whole `style` attribute, as the editor's save() writes it: the engine's
 * render-time style differs (fluid font sizes, custom values dropped beside a
 * preset), and the editor would flag that as invalid.
 *
 * @since 1.6.0
 */
class BlockSupports {

	/**
	 * Attributes the editor keeps in the block comment only, never in the HTML.
	 *
	 * @since 1.6.0
	 * @var array<int, string>
	 */
	public const COMMENT_ONLY = [ 'lock', 'metadata', 'layout', 'templateLock', 'allowedBlocks' ];

	/**
	 * `style` keys applied when the block renders, not saved in its HTML.
	 *
	 * @since 1.6.0
	 * @var array<int, string>
	 */
	private const RENDER_ONLY_STYLES = [ 'elements', 'layout', 'position', 'background' ];

	/**
	 * `style` properties applied when the block renders: block gap is layout,
	 * duotone an SVG filter.
	 *
	 * @since 1.6.0
	 * @var array<int, string>
	 */
	private const RENDER_ONLY_PATHS = [ 'style.spacing.blockGap', 'style.color.duotone' ];

	/**
	 * Preset attribute => [ support feature, subfeature ].
	 *
	 * @since 1.6.0
	 * @var array<string, array{0: string, 1: string|null}>
	 */
	private const SUPPORT_ATTRIBUTES = [
		'className'       => [ 'customClassName', null ],
		'anchor'          => [ 'anchor', null ],
		'ariaLabel'       => [ 'ariaLabel', null ],
		'align'           => [ 'align', null ],
		'backgroundColor' => [ 'color', 'background' ],
		'textColor'       => [ 'color', 'text' ],
		'gradient'        => [ 'color', 'gradients' ],
		'fontSize'        => [ 'typography', 'fontSize' ],
		'fontFamily'      => [ 'typography', 'fontFamily' ],
		'borderColor'     => [ '__experimentalBorder', 'color' ],
	];

	/**
	 * `style` key => support feature.
	 *
	 * @since 1.6.0
	 * @var array<string, string>
	 */
	private const STYLE_FEATURES = [
		'color'      => 'color',
		'typography' => 'typography',
		'spacing'    => 'spacing',
		'border'     => '__experimentalBorder',
		'shadow'     => 'shadow',
		'dimensions' => 'dimensions',
	];

	/**
	 * Registry reader.
	 *
	 * @since 1.6.0
	 * @var BlockSchema
	 */
	private BlockSchema $schema;

	/**
	 * Constructor.
	 *
	 * @param BlockSchema|null $schema Optional registry reader.
	 *
	 * @since 1.6.0
	 */
	public function __construct( ?BlockSchema $schema = null ) {
		$this->schema = $schema ?? new BlockSchema();
	}

	/**
	 * HTML attributes for a block's wrapper, as the editor's save() writes them.
	 *
	 * @param string                $name       Block name.
	 * @param array<string, mixed>  $attributes Block attributes.
	 * @param array<int, string>    $classes    Block-specific classes, written before the supports' ones.
	 * @param array<string, string> $styles     Block-specific CSS declarations.
	 * @param array<string, mixed>  $extra      Further HTML attributes (string value, true for a bare attribute, null to skip).
	 * @return string Attribute string with a leading space, or ''.
	 *
	 * @since 1.6.0
	 */
	public function wrapper( string $name, array $attributes, array $classes = [], array $styles = [], array $extra = [] ): string {
		$supported = $this->apply( $name, $attributes );

		// The generated wp-block-* class leads, as in the editor's output.
		$supported_classes = $this->split_classes( $supported['class'] ?? '' );
		$generated         = array_filter( $supported_classes, static fn ( string $c ): bool => str_starts_with( $c, 'wp-block-' ) );
		$class             = array_merge( $generated, $classes, array_diff( $supported_classes, $generated ) );
		if ( $this->writes_link_color( $name, $attributes ) ) {
			$class[] = 'has-link-color';
		}

		$style = $this->css( $styles ) . $this->saved_style( $name, $attributes );

		unset( $supported['class'], $supported['style'] );

		return $this->html_attributes(
			array_merge(
				$extra,
				$supported,
				[
					'class' => implode( ' ', array_unique( array_filter( $class ) ) ),
					'style' => $style,
				]
			)
		);
	}

	/**
	 * Classes and CSS for an inner element (a button's link, an image's img),
	 * built the way the editor's get*ClassesAndStyles() helpers build them.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<int, string>   $features   Any of 'color', 'border', 'spacing', 'shadow', 'typography'.
	 * @return array{class: array<int, string>, style: string}
	 *
	 * @since 1.6.0
	 */
	public function element( array $attributes, array $features ): array {
		$style   = is_array( $attributes['style'] ?? null ) ? $attributes['style'] : [];
		$classes = [];
		$css     = [];

		foreach ( $features as $feature ) {
			$value = $style[ $feature ] ?? null;

			if ( $feature === 'color' ) {
				$classes = array_merge( $classes, $this->color_classes( $attributes, $style ) );
			} elseif ( $feature === 'border' ) {
				$classes = array_merge( $classes, $this->border_classes( $attributes, $style ) );
			} elseif ( $feature === 'typography' ) {
				$classes = array_merge( $classes, $this->typography_classes( $attributes, $style ) );
				if ( is_array( $value ) ) {
					unset( $value['writingMode'] );
				}
			}

			if ( $value !== null && $value !== [] && $value !== '' ) {
				$css[] = $this->style_engine_css( [ $feature => $value ] );
			}
		}

		return [
			'class' => array_values( array_unique( array_filter( $classes ) ) ),
			'style' => implode( '', $css ),
		];
	}

	/**
	 * Attributes no part of the serializer writes into the block's HTML.
	 *
	 * An attribute is written when it is comment-only, when the template lists it
	 * in $handled (as `name` or `style.key`), or when a block support the block
	 * declares writes it to the wrapper.
	 *
	 * @param string               $name       Block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<int, string>   $handled    Attributes the template writes, or knows to be comment-only.
	 * @return array<int, string> Unwritten attribute paths, e.g. `style.color` or `sizeSlug`.
	 *
	 * @since 1.6.0
	 */
	public function unwritten( string $name, array $attributes, array $handled ): array {
		$supports  = $this->supports_of( $name );
		$handled   = array_flip( array_merge( $handled, self::COMMENT_ONLY ) );
		$unwritten = [];

		foreach ( $attributes as $key => $value ) {
			$key = (string) $key;

			if ( isset( $handled[ $key ] ) ) {
				continue;
			}

			if ( $key === 'style' && is_array( $value ) ) {
				foreach ( $value as $style_key => $style_value ) {
					$path = 'style.' . $style_key;

					if ( isset( $handled[ $path ] ) || in_array( $style_key, self::RENDER_ONLY_STYLES, true ) ) {
						continue;
					}

					$feature = self::STYLE_FEATURES[ $style_key ] ?? null;
					if ( $feature === null ) {
						$unwritten[] = $path;
						continue;
					}

					$properties = is_array( $style_value ) ? array_keys( $style_value ) : [ null ];
					foreach ( $properties as $property ) {
						$property_path = is_string( $property ) ? $path . '.' . $property : $path;

						if ( in_array( $property_path, self::RENDER_ONLY_PATHS, true ) ) {
							continue;
						}

						// The color support calls its gradient subfeature `gradients`.
						$subfeature = $property === 'gradient' && $feature === 'color' ? 'gradients' : $property;

						if ( ! $this->serializes( $supports, $feature, is_string( $subfeature ) ? $subfeature : null ) ) {
							$unwritten[] = $property_path;
						}
					}
				}
				continue;
			}

			$support = self::SUPPORT_ATTRIBUTES[ $key ] ?? null;
			if ( $support !== null && $this->serializes( $supports, $support[0], $support[1] ) ) {
				continue;
			}

			$unwritten[] = $key;
		}

		return $unwritten;
	}

	/**
	 * Whether the block declares a support and lets the engine write it.
	 *
	 * Mirrors block_has_support() and wp_should_skip_block_supports_serialization()
	 * over the registered `supports` array.
	 *
	 * @param array<string, mixed> $supports   The block's supports.
	 * @param string               $feature    Support key.
	 * @param string|null          $subfeature Subfeature, e.g. `background` or `padding`.
	 * @return bool
	 *
	 * @since 1.6.0
	 */
	private function serializes( array $supports, string $feature, ?string $subfeature ): bool {
		// customClassName and className support default to on.
		$value = $supports[ $feature ] ?? ( $feature === 'customClassName' );

		if ( empty( $value ) ) {
			return false;
		}

		if ( is_array( $value ) ) {
			if ( $feature === 'color' && $subfeature !== null ) {
				$flag = $value[ $subfeature ] ?? in_array( $subfeature, [ 'text', 'background' ], true );
				if ( empty( $flag ) ) {
					return false;
				}
			}

			$skip = $value['__experimentalSkipSerialization'] ?? false;
			if ( is_array( $skip ) ) {
				return $subfeature === null || ! in_array( $subfeature, $skip, true );
			}

			return ! $skip;
		}

		return true;
	}

	/**
	 * Run WordPress's supports engine for a block. Only its classes and HTML
	 * attributes are used; {@see saved_style()} builds the inline style.
	 *
	 * @param string               $name       Block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, string> HTML attributes (class, style, id, aria-label).
	 *
	 * @since 1.6.0
	 */
	private function apply( string $name, array $attributes ): array {
		// Core validates these against the REST schema and rejects the 'rich-text' type.
		$attributes = array_diff_key( $attributes, array_flip( $this->schema->unvalidatable_sourced_attributes( $name ) ) );

		if ( class_exists( WP_Block_Supports::class ) ) {
			$previous                           = WP_Block_Supports::$block_to_render;
			WP_Block_Supports::$block_to_render = [
				'blockName' => $name,
				'attrs'     => $attributes,
			];
			$output                             = WP_Block_Supports::get_instance()->apply_block_supports();
			WP_Block_Supports::$block_to_render = $previous;
		} else {
			$output = [];
		}

		// Anchor and aria-label support landed in PHP after WordPress 6.9.
		$supports = $this->supports_of( $name );

		if ( ! isset( $output['id'] ) && ! empty( $supports['anchor'] ) && is_string( $attributes['anchor'] ?? null ) && $attributes['anchor'] !== '' ) {
			$output['id'] = $attributes['anchor'];
		}

		if ( ! isset( $output['aria-label'] ) && ! empty( $supports['ariaLabel'] ) && is_string( $attributes['ariaLabel'] ?? null ) && $attributes['ariaLabel'] !== '' ) {
			$output['aria-label'] = $attributes['ariaLabel'];
		}

		return array_map( 'strval', $output );
	}

	/**
	 * The wrapper's inline CSS, as the editor's style hook saves it: the whole
	 * `style` attribute less what the block skips, its aspect ratio and its
	 * background, which WordPress writes when the block renders.
	 *
	 * @param string               $name       Block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 *
	 * @since 1.6.0
	 */
	private function saved_style( string $name, array $attributes ): string {
		$style = $attributes['style'] ?? null;

		if ( ! is_array( $style ) || $style === [] ) {
			return '';
		}

		$supports = $this->supports_of( $name );

		if ( array_intersect_key( $supports, array_flip( self::STYLE_FEATURES ) ) === [] ) {
			return '';
		}

		foreach ( self::STYLE_FEATURES as $key => $feature ) {
			$skip = is_array( $supports[ $feature ] ?? null ) ? ( $supports[ $feature ]['__experimentalSkipSerialization'] ?? false ) : false;

			if ( $skip === true ) {
				unset( $style[ $key ] );
			} elseif ( is_array( $skip ) && is_array( $style[ $key ] ?? null ) ) {
				foreach ( $skip as $skipped ) {
					unset( $style[ $key ][ $skipped === 'gradients' ? 'gradient' : $skipped ] );
				}
			}
		}

		unset( $style['background'] );
		if ( is_array( $style['dimensions'] ?? null ) ) {
			unset( $style['dimensions']['aspectRatio'] );
		}

		return $this->style_engine_css( $style );
	}

	/**
	 * A registered block's supports.
	 *
	 * @param string $name Block name.
	 * @return array<string, mixed>
	 *
	 * @since 1.6.0
	 */
	private function supports_of( string $name ): array {
		$type = $this->schema->block_type( $name );

		return is_object( $type ) && is_array( $type->supports ?? null ) ? $type->supports : [];
	}

	/**
	 * Whether the editor adds `has-link-color` to this block's wrapper.
	 *
	 * @param string               $name       Block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return bool
	 *
	 * @since 1.6.0
	 */
	private function writes_link_color( string $name, array $attributes ): bool {
		if ( empty( $attributes['style']['elements']['link']['color'] ) ) {
			return false;
		}

		// Any colour support will do: the editor does not check `color.link` here.
		$color = $this->supports_of( $name )['color'] ?? null;
		if ( empty( $color ) ) {
			return false;
		}

		$skip = is_array( $color ) ? ( $color['__experimentalSkipSerialization'] ?? false ) : false;

		return is_array( $skip ) ? ! in_array( 'link', $skip, true ) : ! $skip;
	}

	/**
	 * Colour classes, as the editor's getColorClassesAndStyles() builds them.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $style      The `style` attribute.
	 * @return array<int, string>
	 *
	 * @since 1.6.0
	 */
	private function color_classes( array $attributes, array $style ): array {
		$background = $this->slug( $attributes['backgroundColor'] ?? null );
		$text       = $this->slug( $attributes['textColor'] ?? null );
		$gradient   = $this->slug( $attributes['gradient'] ?? null );
		$color      = is_array( $style['color'] ?? null ) ? $style['color'] : [];

		$has_gradient = $gradient !== '' || ! empty( $color['gradient'] );

		return [
			$text !== '' ? "has-{$text}-color" : '',
			$gradient !== '' ? "has-{$gradient}-gradient-background" : '',
			$background !== '' && ! $has_gradient ? "has-{$background}-background-color" : '',
			$text !== '' || ! empty( $color['text'] ) ? 'has-text-color' : '',
			$background !== '' || ! empty( $color['background'] ) || $has_gradient ? 'has-background' : '',
			! empty( $style['elements']['link']['color'] ) ? 'has-link-color' : '',
		];
	}

	/**
	 * Border classes, as the editor's getBorderClassesAndStyles() builds them.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $style      The `style` attribute.
	 * @return array<int, string>
	 *
	 * @since 1.6.0
	 */
	private function border_classes( array $attributes, array $style ): array {
		$color = $this->slug( $attributes['borderColor'] ?? null );

		return [
			$color !== '' || ! empty( $style['border']['color'] ) ? 'has-border-color' : '',
			$color !== '' ? "has-{$color}-border-color" : '',
		];
	}

	/**
	 * Typography classes, as the editor's getTypographyClassesAndStyles() builds them.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $style      The `style` attribute.
	 * @return array<int, string>
	 *
	 * @since 1.6.0
	 */
	private function typography_classes( array $attributes, array $style ): array {
		$family = $this->slug( $attributes['fontFamily'] ?? null );
		$size   = $this->slug( $attributes['fontSize'] ?? null );
		$align  = $style['typography']['textAlign'] ?? null;

		return [
			$family !== '' ? "has-{$family}-font-family" : '',
			is_string( $align ) && $align !== '' ? "has-text-align-{$align}" : '',
			$size !== '' ? "has-{$size}-font-size" : '',
		];
	}

	/**
	 * A preset slug in kebab case, as the editor writes it into class names.
	 *
	 * @param mixed $value Raw slug.
	 * @return string
	 *
	 * @since 1.6.0
	 */
	private function slug( $value ): string {
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}

		if ( function_exists( '_wp_to_kebab_case' ) ) {
			return _wp_to_kebab_case( $value );
		}

		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', $value ) );
	}

	/**
	 * CSS for a style object through WordPress's style engine.
	 *
	 * @param array<string, mixed> $style Block style object.
	 * @return string
	 *
	 * @since 1.6.0
	 */
	private function style_engine_css( array $style ): string {
		if ( ! function_exists( 'wp_style_engine_get_styles' ) ) {
			return '';
		}

		$styles = wp_style_engine_get_styles( $style );

		return (string) ( $styles['css'] ?? '' );
	}

	/**
	 * Render CSS declarations.
	 *
	 * @param array<string, string> $declarations Property => value.
	 * @return string
	 *
	 * @since 1.6.0
	 */
	private function css( array $declarations ): string {
		$css = '';

		foreach ( $declarations as $property => $value ) {
			if ( $value !== '' ) {
				$css .= $property . ':' . $value . ';';
			}
		}

		return $css;
	}

	/**
	 * Split a class string.
	 *
	 * @param string $classes Space-separated classes.
	 * @return array<int, string>
	 *
	 * @since 1.6.0
	 */
	private function split_classes( string $classes ): array {
		$split = preg_split( '/\s+/', trim( $classes ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $split ) ? $split : [];
	}

	/**
	 * Render HTML attributes, skipping empty ones.
	 *
	 * @param array<string, mixed> $attributes Name => string value, true for a bare attribute.
	 * @return string Attribute string with a leading space, or ''.
	 *
	 * @since 1.6.0
	 */
	public function html_attributes( array $attributes ): string {
		$html = '';

		foreach ( $attributes as $name => $value ) {
			if ( $value === true ) {
				$html .= ' ' . $name;
			} elseif ( is_scalar( $value ) && (string) $value !== '' && $value !== false ) {
				$html .= sprintf( ' %s="%s"', $name, esc_attr( (string) $value ) );
			}
		}

		return $html;
	}
}
