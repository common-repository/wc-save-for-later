<?php
/*
Plugin Name: WPC Save For Later for WooCommerce
Plugin URI: https://wpclever.net/
Description: Enables save-for-later functionality, boosting customer retention and encouraging site revisits.
Version: 3.3.0
Author: WPClever
Author URI: https://wpclever.net
Text Domain: wc-save-for-later
Domain Path: /languages/
Requires Plugins: woocommerce
Requires at least: 4.0
Tested up to: 6.6
WC requires at least: 3.0
WC tested up to: 9.3
*/

defined( 'ABSPATH' ) || exit;

! defined( 'WOOSL_VERSION' ) && define( 'WOOSL_VERSION', '3.3.0' );
! defined( 'WOOSL_LITE' ) && define( 'WOOSL_LITE', __FILE__ );
! defined( 'WOOSL_FILE' ) && define( 'WOOSL_FILE', __FILE__ );
! defined( 'WOOSL_URI' ) && define( 'WOOSL_URI', plugin_dir_url( __FILE__ ) );
! defined( 'WOOSL_REVIEWS' ) && define( 'WOOSL_REVIEWS', 'https://wordpress.org/support/plugin/wc-save-for-later/reviews/?filter=5' );
! defined( 'WOOSL_CHANGELOG' ) && define( 'WOOSL_CHANGELOG', 'https://wordpress.org/plugins/wc-save-for-later/#developers' );
! defined( 'WOOSL_DISCUSSION' ) && define( 'WOOSL_DISCUSSION', 'https://wordpress.org/support/plugin/wc-save-for-later' );
! defined( 'WPC_URI' ) && define( 'WPC_URI', WOOSL_URI );

include 'includes/dashboard/wpc-dashboard.php';
include 'includes/kit/wpc-kit.php';
include 'includes/hpos.php';

if ( ! function_exists( 'woosl_init' ) ) {
	add_action( 'plugins_loaded', 'woosl_init', 11 );

	function woosl_init() {
		// load text-domain
		load_plugin_textdomain( 'wc-save-for-later', false, basename( __DIR__ ) . '/languages/' );

		if ( ! function_exists( 'WC' ) || ! version_compare( WC()->version, '3.0', '>=' ) ) {
			add_action( 'admin_notices', 'woosl_notice_wc' );

			return null;
		}

		if ( ! class_exists( 'WPCleverWoosl' ) && class_exists( 'WC_Product' ) ) {
			class WPCleverWoosl {
				protected static $settings = [];
				protected static $localization = [];
				protected static $instance = null;

				public static function instance() {
					if ( is_null( self::$instance ) ) {
						self::$instance = new self();
					}

					return self::$instance;
				}

				function __construct() {
					self::$settings     = (array) get_option( 'woosl_settings', [] );
					self::$localization = (array) get_option( 'woosl_localization', [] );

					// init
					add_action( 'init', [ $this, 'init' ] );
					add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

					// after user login & logout
					add_action( 'wp_login', [ $this, 'wp_login' ], 10, 2 );
					add_action( 'wp_logout', [ $this, 'wp_logout' ] );

					// user columns
					add_filter( 'manage_users_columns', [ $this, 'users_columns' ] );
					add_filter( 'manage_users_custom_column', [ $this, 'users_columns_content' ], 10, 3 );

					// settings page
					add_action( 'admin_init', [ $this, 'register_settings' ] );
					add_action( 'admin_menu', [ $this, 'admin_menu' ] );

					// settings link
					add_filter( 'plugin_action_links', [ $this, 'action_links' ], 10, 2 );
					add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );

					// my account
					if ( self::get_setting( 'page_myaccount', 'yes' ) === 'yes' ) {
						add_filter( 'woocommerce_account_menu_items', [ $this, 'account_items' ], 99 );
						add_action( 'woocommerce_account_saved-for-later_endpoint', [ $this, 'account_endpoint' ], 99 );
					}

					// ajax add to cart
					add_action( 'wc_ajax_woosl_add_to_cart', [ $this, 'ajax_add_to_cart' ] );

					// ajax add all to cart
					add_action( 'wc_ajax_woosl_add_all_to_cart', [ $this, 'ajax_add_all_to_cart' ] );

					// ajax load
					add_action( 'wc_ajax_woosl_load', [ $this, 'ajax_load' ] );

					// ajax add
					add_action( 'wc_ajax_woosl_add', [ $this, 'ajax_add' ] );

					// ajax add all
					add_action( 'wc_ajax_woosl_add_all', [ $this, 'ajax_add_all' ] );

					// ajax remove
					add_action( 'wc_ajax_woosl_remove', [ $this, 'ajax_remove' ] );

					// save button
					add_action( 'woocommerce_after_cart_item_name', [ $this, 'show_cart_button' ], 10, 2 );

					// save all button
					if ( self::get_setting( 'save_all', 'yes' ) === 'yes' ) {
						add_action( 'woocommerce_cart_actions', [ $this, 'show_button_all' ] );
					}

					// show products
					add_action( 'woocommerce_cart_is_empty', [ $this, 'show_list' ] );

					switch ( self::get_setting( 'position_cart', 'after_cart_table' ) ) {
						case 'before_cart':
							add_action( 'woocommerce_before_cart', [ $this, 'show_list' ] );
							break;
						case 'before_cart_table':
							add_action( 'woocommerce_before_cart_table', [ $this, 'show_list' ] );
							break;
						case 'after_cart_table':
							add_action( 'woocommerce_after_cart_table', [ $this, 'show_list' ] );
							break;
						case 'after_cart':
							add_action( 'woocommerce_after_cart', [ $this, 'show_list' ] );
							break;
					}

					// add button for archive
					$button_position_archive = apply_filters( 'woosl_button_position_archive', self::get_setting( 'button_position_archive', apply_filters( 'woosl_button_position_archive_default', 'none' ) ) );

					if ( ! empty( $button_position_archive ) ) {
						switch ( $button_position_archive ) {
							case 'before_title':
								add_action( 'woocommerce_shop_loop_item_title', [ $this, 'show_archive_button' ], 9 );
								break;
							case 'after_title':
								add_action( 'woocommerce_shop_loop_item_title', [ $this, 'show_archive_button' ], 11 );
								break;
							case 'after_rating':
								add_action( 'woocommerce_after_shop_loop_item_title', [
									$this,
									'show_archive_button'
								], 6 );
								break;
							case 'after_price':
								add_action( 'woocommerce_after_shop_loop_item_title', [
									$this,
									'show_archive_button'
								], 11 );
								break;
							case 'before_add_to_cart':
								add_action( 'woocommerce_after_shop_loop_item', [ $this, 'show_archive_button' ], 9 );
								break;
							case 'after_add_to_cart':
								add_action( 'woocommerce_after_shop_loop_item', [ $this, 'show_archive_button' ], 11 );
								break;
							default:
								add_action( 'woosl_button_position_archive_' . $button_position_archive, [
									$this,
									'show_archive_button'
								] );
						}
					}

					// add button for single
					$button_position_single = apply_filters( 'woosl_button_position_single', self::get_setting( 'button_position_single', apply_filters( 'woosl_button_position_single_default', '0' ) ) );

					if ( ! empty( $button_position_single ) ) {
						if ( is_numeric( $button_position_single ) ) {
							add_action( 'woocommerce_single_product_summary', [
								$this,
								'show_single_button'
							], (int) $button_position_single );
						} else {
							add_action( 'woosl_button_position_single_' . $button_position_single, [
								$this,
								'show_single_button'
							] );
						}
					}

					// WPC Smart Messages
					add_filter( 'wpcsm_locations', [ $this, 'wpcsm_locations' ] );
				}

				function init() {
					// shortcode
					add_shortcode( 'woosl', [ $this, 'shortcode_btn' ] );
					add_shortcode( 'woosl_btn', [ $this, 'shortcode_btn' ] );
					add_shortcode( 'woosl_list', [ $this, 'shortcode_list' ] );

					// my account page
					if ( self::get_setting( 'page_myaccount', 'yes' ) === 'yes' ) {
						add_rewrite_endpoint( 'saved-for-later', EP_PAGES );
					}
				}

				function shortcode_btn( $attrs ) {
					$output = '';
					$attrs  = shortcode_atts( [
						'cart_item_key' => null,
						'product_id'    => null,
						'variation_id'  => null,
						'variation'     => '',
						'price'         => null,
						'context'       => 'cart'
					], $attrs, 'woosl' );

					if ( ! $attrs['product_id'] ) {
						global $product;

						if ( $product && is_a( $product, 'WC_Product' ) ) {
							$attrs['product_id'] = $product->get_id();
						}
					}

					if ( $attrs['product_id'] ) {
						$class = 'woosl-btn woosl-btn-' . $attrs['context'] . ' woosl-btn-' . $attrs['product_id'] . ' woosl-btn-add';

						if ( $attrs['context'] !== 'cart' ) {
							$user_key = self::get_user_key( get_current_user_id() );

							if ( ! empty( $_COOKIE[ $user_key ] ) ) {
								$products = json_decode( stripcslashes( $_COOKIE[ $user_key ] ), true );

								if ( is_array( $products ) && count( $products ) ) {
									foreach ( $products as $product_obj ) {
										if ( ( isset( $product_obj['product_id'] ) && $product_obj['product_id'] == $attrs['product_id'] ) || ( isset( $product_obj['variation_id'] ) && $product_obj['variation_id'] == $attrs['product_id'] ) ) {
											$class .= ' added';
											break;
										}
									}
								}
							}
						}

						$output = '<button class="' . esc_attr( apply_filters( 'woosl_button_class', $class, $attrs ) ) . '" data-product_id="' . esc_attr( $attrs['product_id'] ) . '" data-variation_id="' . esc_attr( $attrs['variation_id'] ) . '" data-price="' . esc_attr( $attrs['price'] ) . '" data-variation="' . esc_attr( $attrs['variation'] ) . '" data-cart_item_key="' . esc_attr( $attrs['cart_item_key'] ) . '" data-context="' . esc_attr( $attrs['context'] ) . '">' . self::localization( 'button', esc_html__( 'Save for later', 'wc-save-for-later' ) ) . '</button>';
					}

					return apply_filters( 'woosl_button', $output, $attrs );
				}

				function shortcode_list( $attrs ) {
					$attrs = shortcode_atts( [
						'context' => 'cart'
					], $attrs, 'woosl_list' );

					ob_start();
					$user_key = self::get_user_key( get_current_user_id() );

					if ( ! empty( $_COOKIE[ $user_key ] ) ) {
						$products = json_decode( stripcslashes( $_COOKIE[ $user_key ] ), true );

						if ( ( $count = count( $products ) ) > 0 ) {
							if ( $attrs['context'] === 'woofc' ) {
								// WPC Fly Cart
								?>
                                <div class="woosl-heading">
                                    <span><?php printf( self::localization( 'heading', /* translators: count */ esc_html__( 'Saved for later products (%s)', 'wc-save-for-later' ) ), $count ); ?></span>
                                </div>
                                <div class="woosl-products">
									<?php
									global $post;

									foreach ( $products as $product_obj ) {
										$product_obj = array_merge( [
											'product_id'   => 0,
											'variation_id' => 0,
											'variation'    => [],
										], $product_obj );

										$product_id   = $product_obj['product_id'];
										$variation_id = $product_obj['variation_id'];
										$variation    = $product_obj['variation'];

										$post = get_post( $product_id );
										setup_postdata( $post );
										$product = wc_get_product( $product_id );

										if ( $product ) {
											?>
                                            <div class="woosl-product" data-product_id="<?php echo esc_attr( $product_id ); ?>" data-variation_id="<?php echo esc_attr( $variation_id ); ?>" data-variation="<?php echo esc_attr( htmlspecialchars( json_encode( $variation ), ENT_QUOTES, 'UTF-8' ) ); ?>" data-context="<?php echo esc_attr( $attrs['context'] ); ?>">
                                                <div class="woosl-product-image woosl-image">
													<?php
													do_action( 'woosl_product_image_above', $product, $attrs );
													echo apply_filters( 'woosl_product_image', $product->get_image(), $product, $attrs );
													do_action( 'woosl_product_image_below', $product, $attrs );
													?>
                                                </div>
                                                <div class="woosl-product-info woosl-info">
                                                    <div class="woosl-product-name woosl-name">
														<?php
														do_action( 'woosl_product_name_above', $product, $attrs );
														echo apply_filters( 'woosl_product_name', '<a href="' . esc_url( $product->get_permalink() ) . '">' . $product->get_name() . '</a>', $product, $attrs );
														do_action( 'woosl_product_name_below', $product, $attrs );
														?>
                                                    </div>
                                                    <div class="woosl-product-price woosl-price">
														<?php
														do_action( 'woosl_product_price_above', $product, $attrs );
														echo apply_filters( 'woosl_product_price', $product->get_price_html(), $product, $attrs );
														do_action( 'woosl_product_price_below', $product, $attrs );
														?>
                                                    </div>
                                                    <div class="woosl-product-atc woosl-atc">
														<?php
														do_action( 'woosl_product_atc_above', $product, $attrs );
														echo '<a href="' . esc_url( get_permalink( $product_id ) ) . '" class="button add_to_cart_button woosl_add_to_cart_button">' . esc_html__( 'Add to cart', 'wc-save-for-later' ) . '</a>';
														do_action( 'woosl_product_atc_below', $product, $attrs );
														?>
                                                    </div>
                                                </div>
                                            </div>
											<?php
										}
									}

									wp_reset_postdata();
									?>
                                </div>
								<?php
							} else {
								?>
                                <table class="shop_table woosl_table woosl-products shop_table_responsive">
                                    <thead>
                                    <tr>
                                        <th colspan="10" class="woosl-heading">
											<?php printf( self::localization( 'heading', /* translators: count */ esc_html__( 'Saved for later products (%s)', 'wc-save-for-later' ) ), $count ); ?>
											<?php if ( self::get_setting( 'add_all', 'yes' ) === 'yes' ) { ?>
                                                <a class="button woosl_add_all_to_cart_button"><?php echo self::localization( 'atc_all', esc_html__( 'Add all to cart', 'wc-save-for-later' ) ); ?></a>
											<?php } ?>
                                        </th>
                                    </tr>
                                    </thead>
                                    <tbody>
									<?php
									global $post;

									foreach ( $products as $product_obj ) {
										$product_obj = array_merge( [
											'product_id'   => 0,
											'variation_id' => 0,
											'variation'    => [],
										], $product_obj );

										$product_id   = $product_obj['product_id'];
										$variation_id = $product_obj['variation_id'];
										$variation    = $product_obj['variation'];

										$post = get_post( $product_id );
										setup_postdata( $post );
										$product = wc_get_product( $variation_id ?: $product_id );

										if ( $product ) {
											?>
                                            <tr class="woosl-product" data-product_id="<?php echo esc_attr( $product_id ); ?>" data-variation_id="<?php echo esc_attr( $variation_id ); ?>" data-variation="<?php echo esc_attr( htmlspecialchars( json_encode( $variation ), ENT_QUOTES, 'UTF-8' ) ); ?>" data-context="<?php echo esc_attr( $attrs['context'] ); ?>">
                                                <td class="woosl-product-remove woosl-remove">
                                                    <button class="woosl-btn woosl-btn-remove" data-product_id="<?php echo esc_attr( $product_id ); ?>" data-variation_id="<?php echo esc_attr( $variation_id ); ?>" data-context="<?php echo esc_attr( $attrs['context'] ); ?>">
														<?php echo esc_html( self::localization( 'remove', esc_html__( 'Remove', 'wc-save-for-later' ) ) ); ?>
                                                    </button>
                                                </td>
                                                <td class="woosl-product-image woosl-image">
													<?php
													do_action( 'woosl_product_image_above', $product, $attrs );
													echo apply_filters( 'woosl_product_image', $product->get_image(), $product, $attrs );
													do_action( 'woosl_product_image_below', $product, $attrs );
													?>
                                                </td>
                                                <td class="woosl-product-name woosl-name" data-title="<?php echo esc_attr( self::localization( 'product', esc_html__( 'Product', 'wc-save-for-later' ) ) ); ?>">
													<?php
													do_action( 'woosl_product_name_above', $product, $attrs );
													echo apply_filters( 'woosl_product_name', '<a href="' . esc_url( $product->get_permalink() ) . '">' . $product->get_name() . '</a>', $product, $attrs );

													if ( $product->is_type( 'variation' ) && is_array( $variation ) && ! empty( $variation ) ) {
														echo '<ul class="woosl-product-attributes">';

														foreach ( $variation as $attr_k => $attr_v ) {
															$attr_k = str_replace( 'attribute_', '', $attr_k );

															if ( taxonomy_exists( $attr_k ) && ( $term = get_term_by( 'slug', $attr_v, $attr_k ) ) ) {
																echo '<li><span>' . wc_attribute_label( $attr_k, $product ) . ':</span> <span>' . esc_html( $term->name ) . '</span></li>';
															} else {
																// custom attribute
																echo '<li><span>' . wc_attribute_label( $attr_k, $product ) . ':</span> <span>' . esc_html( $attr_v ) . '</span></li>';
															}
														}

														echo '</ul>';
													}

													do_action( 'woosl_product_name_below', $product, $attrs );
													?>
                                                </td>
                                                <td class="woosl-product-price woosl-price" data-title="<?php echo esc_attr( self::localization( 'price', esc_html__( 'Price', 'wc-save-for-later' ) ) ); ?>">
													<?php
													do_action( 'woosl_product_price_above', $product, $attrs );
													echo apply_filters( 'woosl_product_price', $product->get_price_html(), $product, $attrs );
													do_action( 'woosl_product_price_below', $product, $attrs );
													?>
                                                </td>
                                                <td class="woosl-product-stock woosl-stock" data-title="<?php echo esc_attr( self::localization( 'stock', esc_html__( 'Stock', 'wc-save-for-later' ) ) ); ?>">
													<?php
													do_action( 'woosl_product_stock_above', $product, $attrs );
													echo apply_filters( 'woosl_product_stock', wc_get_stock_html( $product ), $product, $attrs );
													do_action( 'woosl_product_stock_below', $product, $attrs );
													?>
                                                </td>
                                                <td class="woosl-product-atc woosl-atc" data-title="<?php echo esc_attr( self::localization( 'action', esc_html__( 'Action', 'wc-save-for-later' ) ) ); ?>">
													<?php
													do_action( 'woosl_product_atc_above', $product, $attrs );
													echo '<a href="' . esc_url( get_permalink( $product_id ) ) . '" class="button add_to_cart_button woosl_add_to_cart_button">' . esc_html__( 'Add to cart', 'wc-save-for-later' ) . '</a>';
													do_action( 'woosl_product_atc_below', $product, $attrs );
													?>
                                                </td>
                                            </tr>
											<?php
										}
									}

									wp_reset_postdata();
									?>
                                    </tbody>
                                </table>
								<?php
							}
						}
					}

					return apply_filters( 'woosl_list', ob_get_clean() );
				}

				function ajax_add_to_cart() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woosl-security' ) ) {
						die( 'Permissions check failed!' );
					}

					self::save_user_data();

					if ( ! empty( $_POST['product_id'] ) ) {
						$product_id   = sanitize_text_field( $_POST['product_id'] );
						$variation_id = sanitize_text_field( $_POST['variation_id'] );
						$variation    = is_array( $_POST['variation'] ) && ! empty( $_POST['variation'] ) ? self::sanitize_array( $_POST['variation'] ) : [];

						if ( false !== WC()->cart->add_to_cart( $product_id, 1, $variation_id, $variation ) ) {
							do_action( 'woocommerce_ajax_added_to_cart', $product_id );

							if ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
								wc_add_to_cart_message( [ $product_id => 1 ], true );
							}

							WC_AJAX::get_refreshed_fragments();
						} else {
							$data = [
								'error'       => true,
								'product_url' => apply_filters( 'woocommerce_cart_redirect_after_error', get_permalink( $product_id ), $product_id ),
							];

							wp_send_json( $data );
						}
					}

					wp_die();
				}

				function ajax_add_all_to_cart() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woosl-security' ) ) {
						die( 'Permissions check failed!' );
					}

					self::save_user_data();

					if ( ! empty( $_POST['products'] ) ) {
						if ( $products = json_decode( html_entity_decode( stripcslashes( $_POST['products'] ) ), true ) ) {
							foreach ( $products as $product ) {
								WC()->cart->add_to_cart( $product['product_id'], 1, $product['variation_id'], $product['variation'] );
							}

							WC_AJAX::get_refreshed_fragments();
						} else {
							wp_send_json( [ 'error' => true ] );
						}
					} else {
						wp_send_json( [ 'error' => true ] );
					}

					wp_die();
				}

				function ajax_load() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woosl-security' ) ) {
						die( 'Permissions check failed!' );
					}

					self::show_list();

					wp_die();
				}

				function ajax_add() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woosl-security' ) ) {
						die( 'Permissions check failed!' );
					}

					self::save_user_data();

					if ( isset( $_POST['cart_item_key'] ) ) {
						WC()->cart->remove_cart_item( sanitize_key( $_POST['cart_item_key'] ) );
						WC_AJAX::get_refreshed_fragments();
					}

					wp_die();
				}

				function ajax_add_all() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woosl-security' ) ) {
						die( 'Permissions check failed!' );
					}

					self::save_user_data();

					WC()->cart->empty_cart();
					WC_AJAX::get_refreshed_fragments();

					wp_die();
				}

				function ajax_remove() {
					if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'woosl-security' ) ) {
						die( 'Permissions check failed!' );
					}

					self::save_user_data();
					wp_die();
				}

				function save_user_data() {
					$user_id  = get_current_user_id();
					$user_key = self::get_user_key( $user_id );

					if ( ! empty( $_COOKIE[ $user_key ] ) && is_user_logged_in() ) {
						delete_user_meta( $user_id, $user_key ); // delete old data
						update_user_meta( $user_id, 'woosl_products', $_COOKIE[ $user_key ] );
						$products = json_decode( stripcslashes( $_COOKIE[ $user_key ] ), true );

						if ( is_array( $products ) ) {
							update_user_meta( $user_id, 'woosl_count', count( $products ) );
						}
					}
				}

				function wp_login( $user_login, $user ) {
					if ( isset( $user->data->ID ) && ( $user_id = $user->data->ID ) ) {
						$user_key      = self::get_user_key( $user_id );
						$user_products = get_user_meta( $user_id, $user_key, true );

						if ( ! empty( $user_products ) ) {
							delete_user_meta( $user_id, $user_key ); // delete old data
							update_user_meta( $user_id, 'woosl_products', $user_products );
						}

						$user_products = get_user_meta( $user_id, 'woosl_products', true );

						if ( ! empty( $user_products ) ) {
							$products = json_decode( stripcslashes( $user_products ), true );

							if ( is_array( $products ) ) {
								update_user_meta( $user_id, 'woosl_count', count( $products ) );
							}

							$secure   = apply_filters( 'woosl_cookie_secure', wc_site_is_https() && is_ssl() );
							$httponly = apply_filters( 'woosl_cookie_httponly', false );
							wc_setcookie( $user_key, $user_products, time() + 604800, $secure, $httponly );
						}
					}
				}

				function wp_logout( $user_id ) {
					$secure   = apply_filters( 'woosl_cookie_secure', wc_site_is_https() && is_ssl() );
					$httponly = apply_filters( 'woosl_cookie_httponly', false );
					$user_key = self::get_user_key( $user_id );
					wc_setcookie( $user_key, '', time() + 604800, $secure, $httponly );
					unset( $_COOKIE[ $user_key ] );
				}

				function users_columns( $columns ) {
					$columns['woosl'] = esc_html__( 'Save for later', 'wc-save-for-later' );

					return $columns;
				}

				function users_columns_content( $val, $column_name, $user_id ) {
					if ( $column_name === 'woosl' ) {
						$user_key      = self::get_user_key( $user_id );
						$user_products = get_user_meta( $user_id, $user_key, true );

						if ( ! empty( $user_products ) ) {
							delete_user_meta( $user_id, $user_key ); // delete old data
							update_user_meta( $user_id, 'woosl_products', $user_products );
						}

						$user_products = get_user_meta( $user_id, 'woosl_products', true );
						$user_count    = get_user_meta( $user_id, 'woosl_count', true );

						if ( ! empty( $user_products ) && empty( $user_count ) ) {
							$products = json_decode( stripcslashes( $user_products ), true );

							if ( is_array( $products ) ) {
								$user_count = count( $products );
								update_user_meta( $user_id, 'woosl_count', $user_count );
							}
						}

						if ( ! empty( $user_count ) ) {
							return $user_count;
						}
					}

					return $val;
				}

				function enqueue_scripts() {
					// frontend css & js
					wp_enqueue_style( 'woosl-frontend', WOOSL_URI . 'assets/css/frontend.css', [], WOOSL_VERSION );
					wp_enqueue_script( 'woosl-frontend', WOOSL_URI . 'assets/js/frontend.js', [ 'jquery' ], WOOSL_VERSION, true );
					wp_localize_script( 'woosl-frontend', 'woosl_vars', [
							'wc_ajax_url'   => WC_AJAX::get_endpoint( '%%endpoint%%' ),
							'user_key'      => self::get_user_key( get_current_user_id() ),
							'cart_url'      => wc_get_cart_url(),
							'position_cart' => self::get_setting( 'position_cart', 'after_cart_table' ),
							'nonce'         => wp_create_nonce( 'woosl-security' ),
						]
					);
				}

				function action_links( $links, $file ) {
					static $plugin;

					if ( ! isset( $plugin ) ) {
						$plugin = plugin_basename( __FILE__ );
					}

					if ( $plugin == $file ) {
						$how      = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-woosl&tab=how' ) ) . '">' . esc_html__( 'How to use?', 'wc-save-for-later' ) . '</a>';
						$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-woosl&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'wc-save-for-later' ) . '</a>';
						array_unshift( $links, $how, $settings );
					}

					return (array) $links;
				}

				function row_meta( $links, $file ) {
					static $plugin;

					if ( ! isset( $plugin ) ) {
						$plugin = plugin_basename( __FILE__ );
					}

					if ( $plugin == $file ) {
						$row_meta = [
							'support' => '<a href="' . esc_url( WOOSL_DISCUSSION ) . '" target="_blank">' . esc_html__( 'Community support', 'wc-save-for-later' ) . '</a>',
						];

						return array_merge( $links, $row_meta );
					}

					return (array) $links;
				}

				function account_items( $items ) {
					if ( isset( $items['customer-logout'] ) ) {
						$logout = $items['customer-logout'];
						unset( $items['customer-logout'] );
					} else {
						$logout = '';
					}

					if ( ! isset( $items['saved-for-later'] ) ) {
						$items['saved-for-later'] = apply_filters( 'woosl_myaccount_label', esc_html__( 'Saved for later', 'wc-save-for-later' ) );
					}

					if ( $logout ) {
						$items['customer-logout'] = $logout;
					}

					return $items;
				}

				function account_endpoint() {
					echo apply_filters( 'woosl_myaccount_content', do_shortcode( '[woosl_list]' ) );
				}

				function register_settings() {
					register_setting( 'woosl_settings', 'woosl_settings' );
					register_setting( 'woosl_localization', 'woosl_localization' );
				}

				function admin_menu() {
					add_submenu_page( 'wpclever', esc_html__( 'WPC Save For Later', 'wc-save-for-later' ), esc_html__( 'Save For Later', 'wc-save-for-later' ), 'manage_options', 'wpclever-woosl', [
						$this,
						'admin_menu_content'
					] );
				}

				function admin_menu_content() {
					$active_tab = sanitize_key( $_GET['tab'] ?? 'settings' );
					?>
                    <div class="wpclever_settings_page wrap">
                        <h1 class="wpclever_settings_page_title"><?php echo esc_html__( 'WPC Save For Later', 'wc-save-for-later' ) . ' ' . esc_html( WOOSL_VERSION ); ?></h1>
                        <div class="wpclever_settings_page_desc about-text">
                            <p>
								<?php printf( /* translators: stars */ esc_html__( 'Thank you for using our plugin! If you are satisfied, please reward it a full five-star %s rating.', 'wc-save-for-later' ), '<span style="color:#ffb900">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' ); ?>
                                <br/>
                                <a href="<?php echo esc_url( WOOSL_REVIEWS ); ?>" target="_blank"><?php esc_html_e( 'Reviews', 'wc-save-for-later' ); ?></a> |
                                <a href="<?php echo esc_url( WOOSL_CHANGELOG ); ?>" target="_blank"><?php esc_html_e( 'Changelog', 'wc-save-for-later' ); ?></a> |
                                <a href="<?php echo esc_url( WOOSL_DISCUSSION ); ?>" target="_blank"><?php esc_html_e( 'Discussion', 'wc-save-for-later' ); ?></a>
                            </p>
                        </div>
						<?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) { ?>
                            <div class="notice notice-success is-dismissible">
                                <p><?php esc_html_e( 'Settings updated.', 'wc-save-for-later' ); ?></p>
                            </div>
						<?php } ?>
                        <div class="wpclever_settings_page_nav">
                            <h2 class="nav-tab-wrapper">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woosl&tab=how' ) ); ?>" class="<?php echo esc_attr( $active_tab == 'how' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'How to use?', 'wc-save-for-later' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woosl&tab=settings' ) ); ?>" class="<?php echo esc_attr( $active_tab == 'settings' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'Settings', 'wc-save-for-later' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woosl&tab=localization' ) ); ?>" class="<?php echo esc_attr( $active_tab == 'localization' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'Localization', 'wc-save-for-later' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-kit' ) ); ?>" class="nav-tab">
									<?php esc_html_e( 'Essential Kit', 'wc-save-for-later' ); ?>
                                </a>
                            </h2>
                        </div>
                        <div class="wpclever_settings_page_content">
							<?php if ( $active_tab == 'how' ) { ?>
                                <div class="wpclever_settings_page_content_text">
                                    <p>
										<?php esc_html_e( 'After install & active plugin, you can see the save for later functionality on the Cart page.', 'wc-save-for-later' ); ?>
                                    </p>
                                    <p><img src="<?php echo WOOSL_URI; ?>assets/images/how.jpg" alt=""/></p>
                                </div>
							<?php } elseif ( $active_tab === 'settings' ) {
								if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
									flush_rewrite_rules();
								}

								$position_cart  = self::get_setting( 'position_cart', 'after_cart_table' );
								$save_all       = self::get_setting( 'save_all', 'yes' );
								$add_all        = self::get_setting( 'add_all', 'yes' );
								$page_myaccount = self::get_setting( 'page_myaccount', 'yes' );
								?>
                                <form method="post" action="options.php">
                                    <table class="form-table">
                                        <tr class="heading">
                                            <th scope="row"><?php esc_html_e( 'General', 'wc-save-for-later' ); ?></th>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Position on Cart page', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <select name="woosl_settings[position_cart]">
                                                    <option value="before_cart" <?php selected( $position_cart, 'before_cart' ); ?>><?php esc_html_e( 'Before cart', 'wc-save-for-later' ); ?></option>
                                                    <option value="before_cart_table" <?php selected( $position_cart, 'before_cart_table' ); ?>><?php esc_html_e( 'Before cart table', 'wc-save-for-later' ); ?></option>
                                                    <option value="after_cart_table" <?php selected( $position_cart, 'after_cart_table' ); ?>><?php esc_html_e( 'After cart table', 'wc-save-for-later' ); ?></option>
                                                    <option value="after_cart" <?php selected( $position_cart, 'after_cart' ); ?>><?php esc_html_e( 'After cart', 'wc-save-for-later' ); ?></option>
                                                    <option value="none" <?php selected( $position_cart, 'none' ); ?>><?php esc_html_e( 'None (hide it)', 'wc-save-for-later' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'You also can use shortcode [woosl_list] to place it where you want.', 'wc-save-for-later' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Show "Save all for later"', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <select name="woosl_settings[save_all]">
                                                    <option value="yes" <?php selected( $save_all, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wc-save-for-later' ); ?></option>
                                                    <option value="no" <?php selected( $save_all, 'no' ); ?>><?php esc_html_e( 'No', 'wc-save-for-later' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Show "Add all to cart"', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <select name="woosl_settings[add_all]">
                                                    <option value="yes" <?php selected( $add_all, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wc-save-for-later' ); ?></option>
                                                    <option value="no" <?php selected( $add_all, 'no' ); ?>><?php esc_html_e( 'No', 'wc-save-for-later' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Add "Saved for later" page to My Account', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <select name="woosl_settings[page_myaccount]">
                                                    <option value="yes" <?php selected( $page_myaccount, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wc-save-for-later' ); ?></option>
                                                    <option value="no" <?php selected( $page_myaccount, 'no' ); ?>><?php esc_html_e( 'No', 'wc-save-for-later' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr class="heading">
                                            <th scope="row"><?php esc_html_e( 'Button', 'wc-save-for-later' ); ?></th>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Position on archive page', 'wc-save-for-later' ); ?></th>
                                            <td>
												<?php
												$position_archive  = apply_filters( 'woosl_button_position_archive', 'default' );
												$positions_archive = apply_filters( 'woosl_button_positions_archive', [
													'before_title'       => esc_html__( 'Above title', 'wc-save-for-later' ),
													'after_title'        => esc_html__( 'Under title', 'wc-save-for-later' ),
													'after_rating'       => esc_html__( 'Under rating', 'wc-save-for-later' ),
													'after_price'        => esc_html__( 'Under price', 'wc-save-for-later' ),
													'before_add_to_cart' => esc_html__( 'Above add to cart button', 'wc-save-for-later' ),
													'after_add_to_cart'  => esc_html__( 'Under add to cart button', 'wc-save-for-later' ),
													'none'               => esc_html__( 'None (hide it)', 'wc-save-for-later' ),
												] );
												?>
                                                <label>
                                                    <select name="woosl_settings[button_position_archive]" <?php echo( $position_archive !== 'default' ? 'disabled' : '' ); ?>>
														<?php
														if ( $position_archive === 'default' ) {
															$position_archive = self::get_setting( 'button_position_archive', apply_filters( 'woosl_button_position_archive_default', 'none' ) );
														}

														foreach ( $positions_archive as $k => $p ) {
															echo '<option value="' . esc_attr( $k ) . '" ' . ( ( $k === $position_archive ) || ( empty( $position_archive ) && empty( $k ) ) ? 'selected' : '' ) . '>' . esc_html( $p ) . '</option>';
														}
														?>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Position on single page', 'wc-save-for-later' ); ?></th>
                                            <td>
												<?php
												$position_single  = apply_filters( 'woosl_button_position_single', 'default' );
												$positions_single = apply_filters( 'woosl_button_positions_single', [
													'6'  => esc_html__( 'Under title', 'wc-save-for-later' ),
													'11' => esc_html__( 'Under rating', 'wc-save-for-later' ),
													'21' => esc_html__( 'Under excerpt', 'wc-save-for-later' ),
													'29' => esc_html__( 'Above add to cart button', 'wc-save-for-later' ),
													'31' => esc_html__( 'Under add to cart button', 'wc-save-for-later' ),
													'41' => esc_html__( 'Under meta', 'wc-save-for-later' ),
													'51' => esc_html__( 'Under sharing', 'wc-save-for-later' ),
													'0'  => esc_html__( 'None (hide it)', 'wc-save-for-later' ),
												] );
												?>
                                                <label>
                                                    <select name="woosl_settings[button_position_single]" <?php echo( $position_single !== 'default' ? 'disabled' : '' ); ?>>
														<?php
														if ( $position_single === 'default' ) {
															$position_single = self::get_setting( 'button_position_single', apply_filters( 'woosl_button_position_single_default', '0' ) );
														}

														foreach ( $positions_single as $k => $p ) {
															echo '<option value="' . esc_attr( $k ) . '" ' . ( ( strval( $k ) === strval( $position_single ) ) || ( $k === $position_single ) || ( empty( $position_single ) && empty( $k ) ) ? 'selected' : '' ) . '>' . esc_html( $p ) . '</option>';
														}
														?>
                                                    </select> </label>
                                            </td>
                                        </tr>
                                        <tr class="submit">
                                            <th colspan="2">
												<?php settings_fields( 'woosl_settings' ); ?><?php submit_button(); ?>
                                            </th>
                                        </tr>
                                    </table>
                                </form>
							<?php } elseif ( $active_tab === 'localization' ) { ?>
                                <form method="post" action="options.php">
                                    <table class="form-table">
                                        <tr class="heading">
                                            <th scope="row"><?php esc_html_e( 'Localization', 'wc-save-for-later' ); ?></th>
                                            <td>
												<?php esc_html_e( 'Leave blank to use the default text and its equivalent translation in multiple languages.', 'wc-save-for-later' ); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Save for later', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="woosl_localization[button]" value="<?php echo esc_attr( self::localization( 'button' ) ); ?>" placeholder="<?php esc_attr_e( 'Save for later', 'wc-save-for-later' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Remove', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="woosl_localization[remove]" value="<?php echo esc_attr( self::localization( 'remove' ) ); ?>" placeholder="<?php esc_attr_e( 'Remove', 'wc-save-for-later' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Save all for later', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="woosl_localization[button_all]" value="<?php echo esc_attr( self::localization( 'button_all' ) ); ?>" placeholder="<?php esc_attr_e( 'Save all for later', 'wc-save-for-later' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Add all to cart', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="woosl_localization[atc_all]" value="<?php echo esc_attr( self::localization( 'atc_all' ) ); ?>" placeholder="<?php esc_attr_e( 'Add all to cart', 'wc-save-for-later' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Heading', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="woosl_localization[heading]" value="<?php echo esc_attr( self::localization( 'heading' ) ); ?>" placeholder="<?php /* translators: count */
												esc_attr_e( 'Saved for later products (%s)', 'wc-save-for-later' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Product', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="woosl_localization[product]" value="<?php echo esc_attr( self::localization( 'product' ) ); ?>" placeholder="<?php esc_attr_e( 'Product', 'wc-save-for-later' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Price', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="woosl_localization[price]" value="<?php echo esc_attr( self::localization( 'price' ) ); ?>" placeholder="<?php esc_attr_e( 'Price', 'wc-save-for-later' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Stock', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="woosl_localization[stock]" value="<?php echo esc_attr( self::localization( 'stock' ) ); ?>" placeholder="<?php esc_attr_e( 'Stock', 'wc-save-for-later' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Action', 'wc-save-for-later' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="woosl_localization[action]" value="<?php echo esc_attr( self::localization( 'action' ) ); ?>" placeholder="<?php esc_attr_e( 'Action', 'wc-save-for-later' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr class="submit">
                                            <th colspan="2">
												<?php settings_fields( 'woosl_localization' ); ?><?php submit_button(); ?>
                                            </th>
                                        </tr>
                                    </table>
                                </form>
							<?php } ?>
                        </div><!-- /.wpclever_settings_page_content -->
                        <div class="wpclever_settings_page_suggestion">
                            <div class="wpclever_settings_page_suggestion_label">
                                <span class="dashicons dashicons-yes-alt"></span> Suggestion
                            </div>
                            <div class="wpclever_settings_page_suggestion_content">
                                <div>
                                    To display custom engaging real-time messages on any wished positions, please install
                                    <a href="https://wordpress.org/plugins/wpc-smart-messages/" target="_blank">WPC Smart Messages</a> plugin. It's free!
                                </div>
                                <div>
                                    Wanna save your precious time working on variations? Try our brand-new free plugin
                                    <a href="https://wordpress.org/plugins/wpc-variation-bulk-editor/" target="_blank">WPC Variation Bulk Editor</a> and
                                    <a href="https://wordpress.org/plugins/wpc-variation-duplicator/" target="_blank">WPC Variation Duplicator</a>.
                                </div>
                            </div>
                        </div>
                    </div>
					<?php
				}

				function show_archive_button() {
					echo do_shortcode( '[woosl_btn context="archive"]' );
				}

				function show_single_button() {
					echo do_shortcode( '[woosl_btn context="single"]' );
				}

				function show_cart_button( $cart_item, $cart_item_key ) {
					if ( $cart_item['data']->is_type( 'variation' ) && is_array( $cart_item['variation'] ) ) {
						$variation = htmlspecialchars( json_encode( $cart_item['variation'] ), ENT_QUOTES, 'UTF-8' );
					} else {
						$variation = '';
					}

					echo do_shortcode( '[woosl_btn product_id="' . $cart_item['product_id'] . '" variation_id="' . $cart_item['variation_id'] . '" price="' . $cart_item['data']->get_price() . '" variation="' . $variation . '" cart_item_key="' . $cart_item_key . '"]' );
				}

				function show_button_all() {
					echo '<button class="button woosl-btn-all">' . self::localization( 'button_all', esc_html__( 'Save all for later', 'wc-save-for-later' ) ) . '</button>';
				}

				function show_list() {
					echo do_shortcode( '[woosl_list]' );
				}

				public static function get_settings() {
					return apply_filters( 'woosl_get_settings', self::$settings );
				}

				public static function get_setting( $name, $default = false ) {
					if ( ! empty( self::$settings ) && isset( self::$settings[ $name ] ) ) {
						$setting = self::$settings[ $name ];
					} else {
						$setting = get_option( 'woosl_' . $name, $default );
					}

					return apply_filters( 'woosl_get_setting', $setting, $name, $default );
				}

				public static function localization( $key = '', $default = '' ) {
					$str = '';

					if ( ! empty( $key ) && ! empty( self::$localization[ $key ] ) ) {
						$str = self::$localization[ $key ];
					} elseif ( ! empty( $default ) ) {
						$str = $default;
					}

					return esc_html( apply_filters( 'woosl_localization_' . $key, $str ) );
				}

				function get_user_key( $user_id ) {
					$user_key = 'woosl_products_';
					$key_str  = 'wpcmonster';

					foreach ( str_split( strval( $user_id ) ) as $char ) {
						$user_key .= $key_str[ $char ];
					}

					return apply_filters( 'woosl_get_user_key', $user_key, $user_id );
				}

				function sanitize_array( $arr ) {
					foreach ( (array) $arr as $k => $v ) {
						if ( is_array( $v ) ) {
							$arr[ $k ] = self::sanitize_array( $v );
						} else {
							$arr[ $k ] = sanitize_text_field( $v );
						}
					}

					return $arr;
				}

				function wpcsm_locations( $locations ) {
					$locations['WPC Save For Later'] = [
						'woosl_product_image_above' => esc_html__( 'Above product image', 'wc-save-for-later' ),
						'woosl_product_image_below' => esc_html__( 'Below product image', 'wc-save-for-later' ),
						'woosl_product_name_above'  => esc_html__( 'Above product name', 'wc-save-for-later' ),
						'woosl_product_name_below'  => esc_html__( 'Below product name', 'wc-save-for-later' ),
						'woosl_product_price_above' => esc_html__( 'Above product price', 'wc-save-for-later' ),
						'woosl_product_price_below' => esc_html__( 'Below product price', 'wc-save-for-later' ),
						'woosl_product_stock_above' => esc_html__( 'Above product stock', 'wc-save-for-later' ),
						'woosl_product_stock_below' => esc_html__( 'Below product stock', 'wc-save-for-later' ),
						'woosl_product_atc_above'   => esc_html__( 'Above add-to-cart button', 'wc-save-for-later' ),
						'woosl_product_atc_below'   => esc_html__( 'Below add-to-cart button', 'wc-save-for-later' ),
					];

					return $locations;
				}
			}

			return WPCleverWoosl::instance();
		}

		return null;
	}
}

if ( ! function_exists( 'woosl_notice_wc' ) ) {
	function woosl_notice_wc() {
		?>
        <div class="error">
            <p><?php esc_html_e( 'WPC Save For Later require WooCommerce version 3.0 or greater.', 'wc-save-for-later' ); ?></p>
        </div>
		<?php
	}
}
