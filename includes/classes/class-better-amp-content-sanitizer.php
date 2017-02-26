<?php

/**
 * Strips blacklisted tags and attributes from content.
 *
 * Note: Base codes was copied from Automattic/AMP plugin: http://github.com/Automattic/amp-wp
 *
 * @since     1.0.0
 */
class Better_AMP_Content_Sanitizer {

	/**
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public static $enable_url_transform = TRUE;


	/**
	 * Store Better_AMP_HTML_Util dom object
	 *
	 * @var Better_AMP_HTML_Util
	 *
	 * @since 1.1
	 */
	public $dom;


	/**
	 * Store list of attributes which is allow for any tag
	 *
	 * @var array
	 *
	 * @since 1.1
	 */
	public $general_attrs = array(
		'class'  => TRUE,
		'on'     => TRUE,
		'id'     => TRUE,
		'layout' => TRUE,
		'width'  => TRUE,
		'height' => TRUE,
		'sizes'  => TRUE,
	);


	/**
	 * Store tabindex number
	 *
	 * @var int
	 *
	 * @since 1.1
	 */
	public $tabindex = 10;


	/**
	 * Store html tags list
	 *
	 * @var array
	 *
	 * @since 1.1
	 */
	public $tags = array();


	/**
	 * @since 1.0.0
	 */
	const PATTERN_REL_WP_ATTACHMENT = '#wp-att-([\d]+)#';

	public function __construct( Better_AMP_HTML_Util $dom ) {
		$this->dom = $dom;
	}


	/**
	 * Prepare html content for amp version it removes:
	 * 1) invalid tags
	 * 2) invalid attributes
	 * 3) invalid url protocols
	 *
	 * @since 1.0.0
	 */
	public function sanitize() {

		$blacklisted_attributes = $this->get_blacklisted_attributes();

		$this->sanitize_document();

		$tags = array();
		include BETTER_AMP_INC . 'tags-list.php';
		$this->tags = $tags;

		$this->strip_attributes_recursive( $this->dom->get_body_node(), $blacklisted_attributes );
		$this->tags = array();
	}

	/**
	 * List of blacklisted attributes
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_blacklisted_attributes() {
		return array(
			'style',
			'size',
		);
	}

	/**
	 * Stripes attributes on nodes and childs
	 *
	 * @param DOMElement $node
	 * @param array      $bad_attributes
	 *
	 * @since 1.0.0
	 */
	private function strip_attributes_recursive( $node, $bad_attributes ) {

		if ( ! isset( $node->nodeType ) || $node->nodeType !== XML_ELEMENT_NODE ) {
			return;
		}

		if ( ! isset( $this->tags[ $node->tagName ] ) ) { // remove invalid tag
			self::remove_element( $node );

			return;
		}

		$node_name = $node->nodeName;

		// Some nodes may contain valid content but are themselves invalid.
		// Remove the node but preserve the children.

		if ( $node->hasAttributes() ) {

			$length = $node->attributes->length;

			for ( $i = $length - 1; $i >= 0; $i -- ) {
				$attribute = $node->attributes->item( $i );

				$attribute_name = strtolower( $attribute->name );

				if ( $attribute_name === 'style' ) {
					$this->save_element_style( $node, $attribute );
				}

				if ( in_array( $attribute_name, $bad_attributes ) ) {
					$node->removeAttribute( $attribute_name );

					continue;
				}

				// on* attributes (like onclick) are a special case
				if ( 0 === stripos( $attribute_name, 'on' ) && $attribute_name != 'on' ) {

					$node->removeAttribute( $attribute_name );

					continue;
				}
			}
		}

		$length = $node->childNodes->length;

		for ( $i = $length - 1; $i >= 0; $i -- ) {
			$child_node = $node->childNodes->item( $i );

			$this->strip_attributes_recursive( $child_node, $bad_attributes );
		}

		if ( 'font' === $node_name ) {
			$this->replace_node_with_children( $node );
		}
	}


	/**
	 * Remove the wrapper of node
	 *
	 * @param $node
	 *
	 * @since 1.0.0
	 */
	private function replace_node_with_children( $node ) {
		// If the node has children and also has a parent node,
		// clone and re-add all the children just before current node.
		if ( $node->hasChildNodes() && $node->parentNode ) {
			foreach ( $node->childNodes as $child_node ) {
				$new_child = $child_node->cloneNode( TRUE );
				$node->parentNode->insertBefore( $new_child, $node );
			}
		}

		// Remove the node from the parent, if defined.
		if ( $node->parentNode ) {
			$node->parentNode->removeChild( $node );
		}
	}


	/**
	 * Check string to end with
	 *
	 * @param $haystack
	 * @param $needle
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function endswith( $haystack, $needle ) {
		return '' !== $haystack
		       && '' !== $needle
		       && $needle === substr( $haystack, - strlen( $needle ) );
	}


	/**
	 * Sanitize the dimensions to be AMP valid
	 *
	 * @param $value
	 * @param $dimension
	 *
	 * @since 1.0.0
	 *
	 * @return float|int|string
	 */
	public static function sanitize_dimension( $value, $dimension ) {

		if ( empty( $value ) ) {
			return $value;
		}

		if ( FALSE !== filter_var( $value, FILTER_VALIDATE_INT ) ) {
			return absint( $value );
		}

		if ( self::endswith( $value, 'px' ) ) {
			return absint( $value );
		}

		if ( self::endswith( $value, '%' ) ) {
			if ( 'width' === $dimension ) {
				$percentage = absint( $value ) / 100;

				return round( $percentage * better_amp_get_container_width() );
			}
		}

		return '';
	}


	/**
	 * Convert $url to amp version if:
	 * 1) $url was internal
	 * 2) disable flag is not true  {@see turn_url_transform_off_on}
	 *
	 * @param string $url
	 *
	 * @since 1.0.0
	 *
	 * @return string transformed amp url on success or passed $url otherwise.
	 */
	public static function transform_to_amp_url( $url ) {

		if ( ! self::$enable_url_transform ) {
			return $url;
		}

		// check is url internal?
		// todo support parked domains
		$sitedomain = str_replace(
			array(
				'http://www.',
				'https://www.',
				'http://',
				'https://',
			),
			'',
			site_url()
		);

		$sitedomain = rtrim( $sitedomain, '/' );

		if ( preg_match( '#^https?://w*\.?' . preg_quote( $sitedomain, '#' ) . '/?([^/]*)/?(.*?)$#', $url, $matched ) ) {

			// if url was not amp
			if ( $matched[1] !== Better_AMP::STARTPOINT ) {

				if ( $matched[1] !== 'wp-content' ) { // do not convert link which is started with wp-content
					if ( $matched[1] ) {
						$matched[0] = '';
						$path       = implode( '/', $matched );
					} else {
						$path = '/';
					}

					return better_amp_site_url( $path );
				}
			}

		}

		return $url;
	}


	/**
	 * Convert amp $url to none-amp version if $url was internal
	 *
	 * @param string $url
	 *
	 * @since 1.0.0
	 *
	 * @return string transformed none-amp url on success or passed $url otherwise.
	 */
	public static function transform_to_none_amp_url( $url ) {

		// check is url internal?
		// todo support parked domains
		$sitedomain = str_replace(
			array(
				'http://www.',
				'https://www.',
				'http://',
				'https://',
			),
			'',
			site_url()
		);

		$sitedomain = rtrim( $sitedomain, '/' );

		if ( preg_match( '#^https?://w*\.?' . preg_quote( $sitedomain, '#' ) . '/?([^/]*)/?(.*?)$#', $url, $matched ) ) {

			// if url was not amp
			if ( $matched[1] === Better_AMP::STARTPOINT ) {

				if ( $matched[1] ) {
					$matched[0] = '';
					unset( $matched[1] );
					$path = implode( '/', $matched );
				} else {
					$path = '/';
				}

				return site_url( $path );
			}

		}

		return $url;
	}


	/**
	 * Replace internal links with amp version just in href attribute
	 *
	 * @param array $attr list of attributes
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function replace_href_with_amp( $attr ) {

		if ( isset( $attr['href'] ) ) {
			$attr['href'] = self::transform_to_amp_url( $attr['href'] );
		}

		return $attr;
	}


	/**
	 * Trigger url transform status on/off
	 * @see   transform_to_amp_url
	 *
	 * @param bool $is_on
	 *
	 * @since 1.0.0
	 *
	 * @return bool previous situation
	 */
	public static function turn_url_transform_off_on( $is_on ) {
		$prev                       = self::$enable_url_transform;
		self::$enable_url_transform = $is_on;

		return $prev;
	}


	/**
	 * Callback function for preg_replace_callback
	 * to replace html href="" links to amp version
	 *
	 * @param  array $match pattern matches
	 *
	 * @access private
	 *
	 * @since  1.0.0
	 *
	 * @return string
	 */
	private static function _preg_replace_link_callback( $match ) {

		$url  = empty( $match[4] ) ? $match[3] : $match[4];
		$url  = self::transform_to_amp_url( $url );
		$atts = &$match[1];
		$q    = &$match[2];

		return sprintf( '<a %1$shref=%2$s%3$s%2$s', $atts, $q, esc_attr( $url ) );
	}


	/**
	 * Convert all links in html content to amp link
	 * Except links which is started with wp-content
	 *
	 * @param string $content
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function transform_all_links_to_amp( $content ) {

		/**
		 * @copyright $pattern copied from class snoopy
		 * @see       Snoopy::_striplinks
		 */
		$pattern = "'<\s*a\s(.*?)href\s*=\s*	    # find <a href=
						([\"\'])?					# find single or double quote
						(?(2) (.*?)\\2 | ([^\s\>]+))		# if quote found, match up to next matching
													# quote, otherwise match up to next space
						'isx";

		return preg_replace_callback( $pattern, array( __CLASS__, '_preg_replace_link_callback' ), $content );
	}


	/**
	 * @param $element
	 *
	 * @since 1.1
	 */
	public static function remove_element( $element ) {
		$element->parentNode->removeChild( $element );
	}


	/**
	 * @param array      $element_atts
	 * @param DOMElement $element
	 *
	 * @since 1.1
	 * @return array
	 */
	public function get_invalid_attrs( $element_atts, $element ) {

		$invalid_attrs = array();

		switch ( $element->tagName ) {

			case 'amp-img':

				if ( isset( $element_atts['width'] ) && $element_atts['width'] === 'auto' ) {
					$invalid_attrs[] = 'width';
				}
				break;
		}

		return $invalid_attrs;
	}

	/**
	 * @since 1.1
	 */
	public function sanitize_document() {

		$prev_tag_name = FALSE;

		$rules = array();

		include BETTER_AMP_INC . 'sanitizer-rules.php';

		foreach ( $rules as $rule ) {

			if ( $prev_tag_name !== $rule['tag_name'] ) {
				$elements      = $this->dom->getElementsByTagName( $rule['tag_name'] );
				$prev_tag_name = $rule['tag_name'];
			}

			if ( $nodes_count = $elements->length ) {

				foreach ( $rule['attrs'] as $atts ) {

					if ( empty( $atts['name'] ) ) {
						continue;
					}

					for ( $i = $nodes_count - 1; $i >= 0; $i -- ) {

						$element = $elements->item( $i );

						if ( ! $element ) { // if element was deleted
							break 2;
						}

						$element_atts = self::get_node_attributes( $element );
						$atts2remove  = $this->get_invalid_attrs( $element_atts, $element );
						$new_atts     = array();
						$mandatory    = FALSE;

						foreach ( $atts2remove as $attr ) {
							unset( $element_atts[ $attr ] );
						}

						/**
						 * STEP 1) remove height=auto images
						 */

						if ( $rule['tag_name'] === 'amp-img' && isset( $element_atts['height'] ) && $element_atts['height'] === 'auto' ) {
							self::remove_element( $element ); // Remove invalid element
							continue;
						}


						/**
						 * STEP 2) Sanitize layout attribute
						 */


						if ( ! empty( $rule['layouts']['supported_layouts'] ) ) {

							if ( ! empty( $element_atts['layout'] ) ) {

								$layout = strtoupper( $element_atts['layout'] );


								if ( in_array( $layout, $rule['layouts']['supported_layouts'] ) ) { //

									$this->sanitize_layout_attribute( $layout, $element, $element_atts );
								} else { // invalid layout attribute value


									if ( ! empty( $element_atts['width'] ) && ! empty( $element_atts['height'] ) ) {

										$new_atts['layout'] = 'responsive';
									} else {

										$new_atts['layout'] = 'fill';
									}
								}
							} else {

								if ( isset( $element_atts['width'] ) && $element_atts['width'] === 'auto' && ! empty( $element_atts['height'] ) ) {
									// default layout is FIXED-HEIGHT
									if ( ! in_array( 'FIXED-HEIGHT', $rule['layouts']['supported_layouts'] ) ) {

										$atts2remove[] = 'width';
									}
								}
							}
						}


						/**
						 * STEP 3) search for single required attributes
						 */
						if ( ! empty( $atts['mandatory'] ) ) { // if attribute is required

							if ( ! isset( $element_atts[ $atts['name'] ] ) ) {

								self::remove_element( $element ); // Remove invalid element

								continue;
							}

							$mandatory = TRUE;
						}

						/**
						 * STEP 4) search for alternative required attributes
						 */
						if ( ! empty( $atts['mandatory_oneof'] ) ) {

							if ( ! array_intersect_key( $element_atts, $atts['mandatory_oneof'] ) ) { // no required attribute was found
								if ( empty( $atts['value'] ) ) {

									self::remove_element( $element ); // Remove invalid element

									continue;

								} else { // add required attribute to element if attribute value exists

									$new_atts[ $atts['name'] ] = $atts['value'];
								}
							} else {
								$mandatory = TRUE;
							}
						}

						/**
						 * STEP 5) Sanitize attribute value
						 */
						if ( ! empty( $element_atts[ $atts['name'] ] ) ) {

							$remove_element = FALSE;
							foreach ( array( 'value_regex', 'value_regex_case' ) as $regex_field ) {

								if ( ! empty( $atts[ $regex_field ] ) ) {

									$modifier = 'value_regex_case' === $regex_field ? 'i' : '';

									if ( ! preg_match( '#^' . $atts[ $regex_field ] . '$#' . $modifier, $element_atts[ $atts['name'] ] ) ) {


										if ( $mandatory ) {
											$remove_element = TRUE;
										} else {

											$atts2remove[] = $atts['name'];
											break;
										}
									}
								}
							}

							if ( $remove_element ) {

								self::remove_element( $element ); // Remove invalid element
								continue;
							}

							if ( ! empty( $atts['blacklisted_value_regex'] ) ) { // Check blacklist

								if ( ! preg_match( '/' . $atts['blacklisted_value_regex'] . '/', $element_atts[ $atts['name'] ] ) ) {

									$atts2remove[] = $atts['name'];
								}
							}
						}

						/**
						 * STEP 6) Sanitize url value
						 *
						 */

						if ( ! empty( $atts['value_url'] ) ) {

							$val    = isset( $element_atts[ $atts['name'] ] ) ? $element_atts[ $atts['name'] ] : NULL;
							$parsed = $val ? parse_url( $val ) : array();


							// check empty url value
							if ( isset( $atts['value_url']['allow_empty'] ) && ! $atts['value_url']['allow_empty'] ) {
								// empty url is not allowed

								if ( empty( $element_atts[ $atts['name'] ] ) ) { // is url relative ?
									if ( $mandatory ) {
										$remove_element = TRUE;
									} else {

										$atts2remove[] = $atts['name'];
									}
								}
							}


							// check url protocol
							if ( ! empty( $atts['value_url']['allowed_protocol'] ) ) {

								if ( isset( $parsed['scheme'] ) ) {

									if ( ! in_array( $parsed['scheme'], $atts['value_url']['allowed_protocol'] ) ) { // invalid url protocol

										if ( $mandatory ) {
											$remove_element = TRUE;
										} else {

											$atts2remove[] = $atts['name'];
										}
									}
								}
							}

							if ( isset( $atts['value_url']['allow_relative'] ) && ! $atts['value_url']['allow_relative'] ) {
								// relative url is not allowed

								if ( empty( $parsed['host'] ) ) { // is url relative ?
									if ( $mandatory ) {
										$remove_element = TRUE;
									} else {

										$atts2remove[] = $atts['name'];
									}
								}
							}

							if ( ! empty( $remove_element ) ) {

								self::remove_element( $element ); // Remove invalid element
								continue;
							}
						}


						/**
						 * STEP 7) Sanitize attribute with fixed value
						 */
						if ( isset( $atts['value'] ) && isset( $element_atts[ $atts['name'] ] ) ) {

							if ( $element_atts[ $atts['name'] ] !== $atts['value'] ) { // is current value invalid?
								$new_atts[ $atts['name'] ] = $atts['value']; // set valid value
							}
						}


						/**
						 * STEP 8) Filter attributes list
						 */

						if ( sizeof( $atts ) === 1 ) { // check is attribute boolean


							if ( $element_atts ) {
								$el_atts = $this->_get_rule_attrs_list( $rule );

								foreach ( $element_atts as $k => $v ) {

									if ( isset( $this->general_attrs[ $k ] ) ) {
										continue;
									}

									if ( substr( $k, 0, 5 ) !== 'data-' ) {
										$atts2remove[ $k ] = $v;
									}
								}
								$atts2remove = array_diff_key( $atts2remove, $el_atts ); // Filter extra attrs
								$atts2remove = array_keys( $atts2remove );
							}
						}

						/**
						 * STEP 9) Sanitize elements with on attribute
						 */

						if ( ! empty( $element_atts['on'] ) ) {

							if ( substr( $element_atts['on'], 0, 4 ) === 'tap:' ) { // now role & tabindex attribute is required

								if ( empty( $element_atts['tabindex'] ) ) {
									$new_atts['tabindex'] = $this->tabindex ++;
								}

								if ( empty( $element_atts['role'] ) ) {
									$new_atts['role'] = $rule['tag_name'];
								}
							}
						}

						/**
						 * STEP 10) Sanitize percentage  with
						 */
						if ( isset( $element_atts['width'] ) && stristr( $element_atts['width'], '%' ) ) {

							$new_atts['width'] = self::sanitize_dimension( $element_atts['width'], 'width' );
						}

						if ( $atts2remove ) {
							$this->dom->remove_attributes( $element, $atts2remove ); // Remove invalid attributes
						}

						if ( $new_atts ) {
							$this->dom->add_attributes( $element, $new_atts ); // add/ update element attribute
						}
					}
				}
			}
		}

		$body = $this->dom->get_body_node();

		if ( $body ) {


			/**
			 * Remove all extra tags
			 */

			$extra_tags = array(
				'script',
				'svg',
				'canvas',
			);

			foreach ( $extra_tags as $tag_name ) {

				$elements = $body->getElementsByTagName( $tag_name );

				if ( $elements->length ) {

					for ( $i = $elements->length - 1; $i >= 0; $i -- ) {
						$element = $elements->item( $i );

						if ( $tag_name === 'script' && $element->parentNode->tagName === 'amp-analytics' ) {

							$atts = self::get_node_attributes( $element );

							if ( isset( $atts['type'] ) && $atts['type'] === 'application/json' ) {
								continue;
							}
						}

						self::remove_element( $element );
					}
				}
			}

			/**
			 * Remove extra style tags and collect their contents
			 */
			$elements = $body->getElementsByTagName( 'style' );

			if ( $elements->length ) {

				for ( $i = $elements->length - 1; $i >= 0; $i -- ) {
					$element = $elements->item( $i );

					$style = preg_replace( '/\s*!\s*important/', '', $element->nodeValue ); // Remove !important
					better_amp_add_inline_style( $style );

					self::remove_element( $element );
				}
			}

			/**
			 * Sanitize Form Tag
			 */

			$elements = $body->getElementsByTagName( 'form' );

			if ( $elements->length ) {

				better_amp_enqueue_script( 'amp-form', 'https://cdn.ampproject.org/v0/amp-form-0.1.js"' );

				$valid_target_values = array(
					'_blank' => TRUE,
					'_top'   => TRUE,
				);

				for ( $i = $elements->length - 1; $i >= 0; $i -- ) {

					$action  = '';
					$element = $elements->item( $i );

					$element_atts = self::get_node_attributes( $element );

					if ( ! empty( $element_atts['action'] ) ) {

						$element->removeAttribute( 'action' );
						$action = $element_atts['action'];
					}

					if ( ! empty( $element_atts['action-xhr'] ) ) {

						$action = $element_atts['action-xhr'];
					}

					$action_xhr = '';

					if ( $action ) {

						$parsed_action = parse_url( $action );
						if ( ! isset( $parsed_action['schema'] ) && ! empty( $parsed_action['path'] ) ) {

							$action_xhr = $parsed_action['path'];
						} else if ( isset( $parsed_action['schema'] ) && $parsed_action['schema'] === 'https' ) {

							$action_xhr = $action_xhr;
						} else if ( $_parsed = self::parse_internal_url( $action ) ) {


							$action_xhr = empty( $_parsed['path'] ) ? '/' : $_parsed['path'];
						} else { // invalid element - cannot detect action

							self::remove_element( $element );
							continue;
						}

					} else {

						$action_xhr = add_query_arg( FALSE, FALSE ); // relative path to current page
					}

					$action_attr_name = 'action-xhr';
					if ( ! isset( $element_atts['method'] ) || strtolower( $element_atts['method'] ) === 'get' ) {
						// Swap action-xr with action on get methods

						$action_attr_name = 'action';
					}

					$element->setAttribute( $action_attr_name, $action_xhr );

					/**
					 * Sanitize target attribute
					 */
					if (
						( isset( $element_atts['target'] ) && ! isset( $valid_target_values[ $element_atts['target'] ] ) )
						||
						! isset( $element_atts['target'] )
					) {

						$element->setAttribute( 'target', '_top' );
					}

					//@todo sanitize input elements
				}
			}


			/**
			 * Replace audio/video tag with amp-audio/video
			 */

			$replaceTags = array(

				'audio' => array(
					'amp-audio',
					'https://cdn.ampproject.org/v0/amp-audio-0.1.js'
				),
				'video' => array(
					'amp-video',
					'https://cdn.ampproject.org/v0/amp-video-0.1.js'
				)

			);
			foreach ( $replaceTags as $tag_name => $tag_info ) {
				$elements = $body->getElementsByTagName( $tag_name );

				if ( $elements->length ) {

					$enqueue = TRUE;

					/**
					 * @var DOMElement $element
					 */
					for ( $i = $elements->length - 1; $i >= 0; $i -- ) {

						$element = $elements->item( $i );

						if ( $element->parentNode->tagName !== 'noscript' ) {

							$source = Better_AMP_HTML_Util::child( $element, 'source', array( 'src' ) );

							if ( empty( $source->attributes['src'] ) ) {

								self::remove_element( $element );
								continue;
							}

							$src = $source->attributes['src']->value;

							if ( ! preg_match( '#^\s*https://#', $src ) ) {

								self::remove_element( $element );
								continue;
							}

							$element->setAttribute( 'src', $src );
							Better_AMP_HTML_Util::renameElement( $element, $tag_info[0] );

							if ( $enqueue ) {

								better_amp_enqueue_script( $tag_info[0], $tag_info[1] );
								$enqueue = FALSE;
							}
						}
					}
				}
			}
		}
	}


	/**
	 * parse url if given url was an internal url
	 *
	 * @param string $url
	 *
	 * @todo  check subdirectory
	 *
	 * @since 1.1
	 * @return array
	 */
	public static function parse_internal_url( $url ) {

		static $current_url_parsed;

		if ( ! $current_url_parsed ) {
			$current_url_parsed = parse_url( site_url() );
		}

		$parsed_url = parse_url( $url );

		if ( ! isset( $parsed_url['host'] ) || $parsed_url['host'] === $current_url_parsed['host'] ) {

			return $parsed_url;
		}

		return array();
	}

	/**
	 *
	 * Get attributes of the element
	 *
	 * @param DOMElement $node
	 *
	 * @since 1.1
	 *
	 * @return array key-value paired attributes
	 */
	public static function get_node_attributes( $node ) {

		$attributes = array();

		foreach ( $node->attributes as $attribute ) {
			$attributes[ $attribute->nodeName ] = $attribute->nodeValue;
		}

		return $attributes;
	}


	/**
	 * Sanitize element attribute value
	 *
	 * @see   https://github.com/ampproject/amphtml/blob/master/spec/amp-html-layout.md
	 *
	 * @param string     $layout
	 * @param DOMElement $element
	 * @param array      $element_atts
	 *
	 * @since 1.1
	 */
	protected function sanitize_layout_attribute( $layout, $element, $element_atts ) {

		$atts2remove = array();


		$required_atts = array(
			'width'  => FALSE,
			'height' => FALSE,
		);

		switch ( strtoupper( $layout ) ) {

			case 'FIXED-HEIGHT':

				// The height attribute must be present. The width attribute must not be present or must be equal to auto.
				$required_atts['height'] = TRUE;
				break;

			case 'FIXED':
			case 'RESPONSIVE':

				// The width and height attributes must be present
				$required_atts['width']  = TRUE;
				$required_atts['height'] = TRUE;
				break;


			case 'FILL':
			case 'CONTAINER':
			case 'FLEX-ITEM':
			case 'NODISPLAY':
				//  No validation required!
				break;
		}


		if (
			$required_atts['width'] &&
			( empty( $element_atts['width'] ) || $element_atts['width'] === 'auto' )
		) {
			$atts2remove[] = 'layout';
		}

		if (
			$required_atts['height'] &&
			( empty( $element_atts['height'] ) || $element_atts['height'] === 'auto' )
		) {
			$atts2remove[] = 'layout';
		}


		if ( $atts2remove ) {
			$this->dom->remove_attributes( $element, $atts2remove ); // Remove invalid attributes
		}
	}


	/**
	 * Collect inline element style and print it out in <style amp-custom> tag
	 *
	 * @param DOMElement $node
	 *
	 * @since 1.1
	 */
	public function save_element_style( $node ) {
		$attributes = self::get_node_attributes( $node );

		if ( ! empty( $attributes['style'] ) ) {

			if ( ! empty( $attributes['id'] ) ) {

				$selector = '#' . $attributes['id'];
			} else {

				$class = isset( $attributes['class'] ) ? $attributes['class'] . ' ' : '';
				$class .= 'e_' . mt_rand();
				$node->setAttribute( 'class', $class );

				$selector = preg_replace( '/[ ]+/', '.', '.' . $class);
				$selector .= $selector; // twice for higher CSS priority
			}

			better_amp_add_inline_style( sprintf( '%s{%s}', $selector, $attributes['style'] ) );
		}
	}

	/**
	 * @param array $rule
	 *
	 * @since 1.1
	 * @return array
	 */
	protected function _get_rule_attrs_list( $rule ) {

		$results = array();

		foreach ( $rule['attrs'] as $d ) {

			if ( isset( $d['name'] ) ) {
				$results[ $d['name'] ] = TRUE;
			}
		}

		return $results;
	}

}
