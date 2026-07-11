<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * GenerateBlocks 2.x dynamic tag: exposes {{sysmda_md_url}} in
 * GenerateBlocks/GeneratePress elements (for example, a Button URL field).
 *
 * It registers itself when GenerateBlocks 2.x is present (like the ACF
 * integration activates with ACF). Keeping the tag registered is intentional:
 * when the post is not servable, the callback returns '' and GenerateBlocks'
 * "required to render" hides the element. This avoids leaving a literal
 * {{sysmda_md_url}} in href when the tag cannot be resolved.
 *
 * API verified against GenerateBlocks 2.2.1 source:
 * - registration: new GenerateBlocks_Register_Dynamic_Tag([...]) on `init`
 * - post resolution: GenerateBlocks_Dynamic_Tags::get_id($options,'post',$instance)
 */
class DynamicTags {

	const TAG = 'sysmda_md_url';

	/**
	 * Attaches tag registration to `init`. register_tag() checks for GenerateBlocks
	 * because its class might not be loaded during boot.
	 */
	public function register(): void {
		// GenerateBlocks registers its tags on `init`; attach after it (priority 20).
		add_action( 'init', array( $this, 'register_tag' ), 20 );
	}

	/**
	 * Registers the tag with GenerateBlocks when its class is available.
	 */
	public function register_tag(): void {
		if ( ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			return; // GenerateBlocks is absent or the installed version lacks Dynamic Tags.
		}

		new \GenerateBlocks_Register_Dynamic_Tag(
			array(
				'title'    => 'Markdown URL (.md)',
				'tag'      => self::TAG,
				'type'     => 'post',
				'supports' => array( 'source' ),
				'return'   => array( __CLASS__, 'get_md_url' ),
			)
		);
	}

	/**
	 * Tag callback: returns the resolved post's .md URL.
	 *
	 * @param array  $options  Tag options (parsed by GenerateBlocks).
	 * @param array  $block    Block data.
	 * @param object $instance Block instance.
	 * @return string .md URL, or '' when not servable.
	 */
	public static function get_md_url( $options, $block, $instance ): string {
		if ( ! class_exists( 'GenerateBlocks_Dynamic_Tags' ) ) {
			return '';
		}

		$id = \GenerateBlocks_Dynamic_Tags::get_id( $options, 'post', $instance );

		if ( ! $id ) {
			return '';
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || ! PostSupport::is_servable( $post ) ) {
			return '';
		}

		return MetadataBuilder::markdown_url( $post );
	}
}
