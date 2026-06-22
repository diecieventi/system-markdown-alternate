<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic Tag per GenerateBlocks 2.x: espone {{sma_md_url}} negli elementi
 * GenerateBlocks/GeneratePress (es. campo URL di un Button).
 *
 * Si auto-registra quando GenerateBlocks 2.x è presente (come l'integrazione
 * ACF si attiva con ACF). Tenere il tag SEMPRE registrato è una scelta voluta:
 * se il post non è servibile il callback restituisce '' e il "required to render"
 * di GenerateBlocks nasconde l'elemento, evitando di lasciare {{sma_md_url}}
 * letterale nell'href (cosa che accadrebbe se il tag non fosse registrato).
 *
 * API verificata sul sorgente di GenerateBlocks 2.2.1:
 * - registrazione: new GenerateBlocks_Register_Dynamic_Tag([...]) su `init`
 * - risoluzione post: GenerateBlocks_Dynamic_Tags::get_id($options,'post',$instance)
 */
class DynamicTags {

	const TAG = 'sma_md_url';

	/**
	 * Aggancia la registrazione del tag su `init`. La presenza di GenerateBlocks
	 * viene verificata in register_tag() (la classe potrebbe non essere ancora
	 * caricata al momento del boot).
	 */
	public function register(): void {
		// GenerateBlocks registra i propri tag su `init`; ci agganciamo dopo (prio 20).
		add_action( 'init', array( $this, 'register_tag' ), 20 );
	}

	/**
	 * Registra il tag presso GenerateBlocks, se la classe è disponibile.
	 */
	public function register_tag(): void {
		if ( ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			return; // GenerateBlocks assente o versione senza Dynamic Tags.
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
	 * Callback del tag: restituisce l'URL del .md del post risolto.
	 *
	 * @param array  $options  Opzioni del tag (parsate da GenerateBlocks).
	 * @param array  $block    Dati del blocco.
	 * @param object $instance Istanza del blocco.
	 * @return string URL del .md, o '' se non servibile.
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

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$types = (array) apply_filters( 'sma_markdown_supported_post_types', array() );

		if ( ! in_array( $post->post_type, $types, true )
			|| 'publish' !== $post->post_status
			|| post_password_required( $post ) ) {
			return '';
		}

		return MetadataBuilder::markdown_url( $post );
	}
}
