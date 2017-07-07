<?php
namespace ColorMeShop;

use ColorMeShop\Models\Sitemap;
use ColorMeShop\Models\Product_Api;
use ColorMeShop\Swagger\Api\CategoryApi;
use ColorMeShop\Swagger\Api\ProductApi;
use ColorMeShop\Swagger\Api\ShopApi;
use ColorMeShop\Swagger\Configuration;
use Pepabo\OAuth2\Client\Provider\ColorMeShop as OAuth2Client;
use Pimple\Container;

/**
 * プラグインの動作に必要な各種初期化やショートコードの登録などを行う
 *
 * @package ColorMeShop
 */
class Plugin {
	/**
	 * DI コンテナ
	 *
	 * @var \Pimple\Container
	 */
	private $container;

	/**
	 * Plugin constructor.
	 */
	public function __construct() {
		$this->initialize_container();
	}

	/**
	 * @return void
	 */
	public function register() {
		$this->register_shortcode();
		$this->container['admin']->register();

		add_action( 'colormeshop_category', [ $this, 'show_category' ] );
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_action( 'update_option_colorme_wp_settings', [ $this, 'on_update_settings' ] , 10, 2 );
		add_filter( 'document_title_parts', [ $this, 'filter_title' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_filter( 'template_redirect', array( $this, 'handle_template_redirect' ), 1, 0 );
		register_activation_hook( dirname( __DIR__ ) . '/colormeshop-wp-plugin.php', [
			$this,
			'flush_rewrite_rules',
		] );
	}

	/**
	 * タイトルに商品情報を追加する
	 *
	 * @param array $title_parts
	 * @return array
	 */
	public function filter_title( $title_parts ) {
		if (
			! $this->container['target_id']
			|| ! $this->container['product_page_id']
			|| ! is_page( $this->container['product_page_id'] )
		) {
			return $title_parts;
		}

		try {
			$title_parts['title'] = $this->container['swagger.api.product']->getProduct( $this->container['target_id'] )['product']['name'] . ' - ' . $title_parts['title'];
		} catch ( \RuntimeException $e ) {
			if ( $this->container['WP_DEBUG_LOG'] ) {
				error_log( 'タイトルのフィルタに失敗しました : ' . $e->getMessage() );
			}
		}

		return $title_parts;
	}

	/**
	 * プラグイン設定更新のコールバック
	 *
	 * @param array $old 古い設定値
	 * @param array $new 新しい設定値
	 * @return void
	 */
	public function on_update_settings( $old, $new ) {
		// 商品ページIDを元にサイトマップへのリライトを定義するため
		$this->flush_rewrite_rules( $new );
	}

	/**
	 * 商品ページ用のリライトルールを追加する
	 *
	 * @param array $settings プラグインの設定. 管理画面で設定を更新した場合, 更新後の設定値が渡される.
	 * @return void
	 */
	public function add_rewrite_rules( $settings = null ) {
		$product_page_id = ($settings && isset( $settings['product_page_id'] )) ? $settings['product_page_id'] : $this->container['product_page_id'];
		if ( ! $this->is_valid_product_page_id( $product_page_id ) ) {
			return;
		}

		// サイトマップ用のリライトルール
		$product_page_path = str_replace( site_url(), '', get_permalink( $product_page_id ) );
		$trimmed = trim( $product_page_path, '/' );
		add_rewrite_rule( '^' . $trimmed . '/sitemap\.xml$', 'index.php?page_id=' . $product_page_id . '&colorme_sitemap=1', 'top' );
	}

	/**
	 * 商品ページに必要な前処理を行う
	 *
	 * @return void
	 */
	public function handle_template_redirect() {
		if ( ! $this->container['product_page_id'] || ! is_page( $this->container['product_page_id'] ) ) {
			return;
		}

		if ( get_query_var( 'colorme_sitemap' ) ) {
			if ( get_query_var( 'offset' ) === '' || get_query_var( 'offset' ) === null ) {
				$this->output_sitemap_index();
			}
			$this->output_sitemap( get_query_var( 'offset' ) );
		}

		if ( get_query_var( 'colorme_page' ) === 'categories' ) {
			// テーマディレクトリに colorme-categories.php があれば優先する
			$template = get_template_directory() . '/colorme-categories.php';
			if ( file_exists( $template ) ) {
				include $template;
				exit;
			}
			// ヘッダやサイドバー等を残して、本文のみを差し替えるために the_content をフィルタする
			add_filter( 'the_content', [ $this, 'show_categories' ] );
			return;
		}

		if ( get_query_var( 'colorme_page' ) === 'items' ) {
			// テーマディレクトリに colorme-items.php があれば優先する
			$template = get_template_directory() . '/colorme-items.php';
			if ( file_exists( $template ) ) {
				include $template;
				exit;
			}
			add_filter( 'the_content', [ $this, 'show_items' ] );
			return;
		}

		if ( ! get_query_var( 'colorme_item' ) ) {
			$this->show_404();
		}
	}

	/**
	 * 商品カテゴリー 一覧を表示する
	 *
	 * @param string $content
	 * @return string
	 */
	public function show_categories( $content ) {
		if ( ! ob_start() ) {
			if ( $this->container['WP_DEBUG_LOG'] ) {
				error_log( '商品カテゴリー 一覧の表示に失敗しました' );
			}
			return '';
		}

		include __DIR__ . '/../templates/categories.php';
		return ob_get_clean();
	}

	/**
	 * 商品一覧を表示する
	 *
	 * @param string $content
	 * @return string
	 */
	public function show_items( $content ) {
		if ( ! ob_start() ) {
			if ( $this->container['WP_DEBUG_LOG'] ) {
				error_log( '商品一覧の表示に失敗しました' );
			}
			return '';
		}

		include __DIR__ . '/../templates/items.php';
		return ob_get_clean();
	}

	/**
	 * 404 ページを表示する
	 *
	 * @return void
	 */
	private function show_404() {
		// @see https://wpdocs.osdn.jp/404%E3%82%A8%E3%83%A9%E3%83%BC%E3%83%9A%E3%83%BC%E3%82%B8%E3%81%AE%E4%BD%9C%E6%88%90#.E9.81.A9.E5.88.87.E3.81.AA.E3.83.98.E3.83.83.E3.83.80.E3.83.BC.E3.82.92.E9.80.81.E4.BF.A1.E3.81.99.E3.82.8B
		header( 'HTTP/1.1 404 Not Found' );
		global $wp_query;
		$wp_query->is_404 = true;
	}

	/**
	 * サイトマップインデックスを出力する
	 *
	 * @return void
	 */
	private function output_sitemap_index() {
		global $wp_query;
		$wp_query->is_404 = false;
		$wp_query->is_feed = true;

		header( 'Content-Type:text/xml' );
		try {
			echo $this->container['model.sitemap']->generate_index();
		} catch ( \RuntimeException $e ) {
			if ( $this->container['WP_DEBUG_LOG'] ) {
				error_log( 'サイトマップインデックスの出力に失敗しました : ' . $e->getMessage() );
			}
		}
		exit;
	}

	/**
	 * サイトマップを出力する
	 *
	 * @param int $offset
	 * @return void
	 */
	private function output_sitemap( $offset ) {
		global $wp_query;
		$wp_query->is_404 = false;
		$wp_query->is_feed = true;

		header( 'Content-Type:text/xml' );
		try {
			echo $this->container['model.sitemap']->generate( $offset );
		} catch ( \RuntimeException $e ) {
			if ( $this->container['WP_DEBUG_LOG'] ) {
				error_log( 'サイトマップの出力に失敗しました : ' . $e->getMessage() );
			}
		}
		exit;
	}

	/**
	 * クエリ文字列を追加する
	 *
	 * @param array $query_vars
	 * @return array
	 */
	public function add_query_vars( $query_vars ) {
		$query_vars[] = 'colorme_item';
		$query_vars[] = 'colorme_sitemap';
		$query_vars[] = 'colorme_page';
		$query_vars[] = 'category_id_big';
		$query_vars[] = 'category_id_small';
		$query_vars[] = 'offset';
		$query_vars[] = 'page_no';

		return $query_vars;
	}

	/**
	 * @param array $settings プラグインの設定. 管理画面で設定を更新した場合, 更新後の設定値が渡される.
	 */
	public function flush_rewrite_rules( $settings = null ) {
		$this->add_rewrite_rules( $settings );
		flush_rewrite_rules();
	}

	/**
	 * 商品ページ ID を検証する
	 *
	 * @param int $producct_page_id
	 * @return bool
	 */
	public function is_valid_product_page_id( $producct_page_id ) {
		if ( ! $producct_page_id || ! is_numeric( $producct_page_id ) ) {
			return false;
		}

		$p = get_post( $producct_page_id );
		if ( ! $p || $p->post_type !== 'page' ) {
			return false;
		}

		return true;
	}

	/**
	 * DI コンテナの依存関係を定義する
	 *
	 * @return void
	 */
	private function initialize_container() {
		$container          = new Container();

		$container['colorme_wp_settings'] = function ( $c ) {
			return get_option( 'colorme_wp_settings' );
		};

		$container['templates_dir'] = function ( $c ) {
			return __DIR__ . '/../templates';
		};

		$container['plugin_dir_url'] = function ( $c ) {
			return plugin_dir_url( dirname( __DIR__ ) . '/colormeshop-wp-plugin.php' );
		};

		$container['WP_DEBUG_LOG'] = function ( $c ) {
			return defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		};

		$container['token'] = function ( $c ) {
			$settings = $c['colorme_wp_settings'];

			return $settings && array_key_exists( 'token', $settings ) ? $settings['token'] : '';
		};

		$container['client_id'] = function ( $c ) {
			$settings = $c['colorme_wp_settings'];

			return $settings && array_key_exists( 'client_id', $settings ) ? $settings['client_id'] : '';
		};

		$container['client_secret'] = function ( $c ) {
			$settings = $c['colorme_wp_settings'];

			return $settings && array_key_exists( 'client_secret', $settings ) ? $settings['client_secret'] : '';
		};

		$container['product_page_id'] = $container->factory(function ( $c ) {
			// URLリライトを定義する際に最新の設定を取得するために都度DBからとる
			$settings = get_option( 'colorme_wp_settings' );

			return $settings && array_key_exists( 'product_page_id', $settings ) ? $settings['product_page_id'] : '';
		});

		$container['oauth2_client'] = function ( $c ) {
			return new OAuth2Client( [
				'clientId'     => $c['client_id'],
				'clientSecret' => $c['client_secret'],
				'redirectUri'  => admin_url( 'admin-ajax.php?action=colormeshop_callback' ),
			] );
		};

		$container['target_id'] = function ( $c ) {
			global $wp_query;

			return isset( $wp_query->query_vars['colorme_item'] ) ? $wp_query->query_vars['colorme_item'] : null;
		};

		$container['product_page_url'] = function ( $c ) {
			if ( ! $c['product_page_id'] ) {
				return null;
			}

			return get_permalink( $c['product_page_id'] );
		};

		$container['is_mobile'] = function ( $c ) {
			return wp_is_mobile();
		};

		$container['admin'] = function ( $c ) {
			return new Admin(
				$c['oauth2_client'],
				$c['colorme_wp_settings'],
                $c['templates_dir'],
				$c['client_id'],
				$c['client_secret'],
				$c['token'],
				$c['product_page_id']
			);
		};

		$container['url_builder'] = function ( $c ) {
			return new Url_Builder( $c['product_page_url'] );
		};

		$container['model.product_api'] = function ( $c ) {
			return new Product_Api( $c['token'], $c['paginator_factory'] );
		};

		$container['model.sitemap'] = function ( $c ) {
			return new Sitemap( $c['model.product_api'], $c['url_builder'] );
		};

		$container['paginator_factory'] = function ( $c ) {
			return new Paginator_Factory( $c['product_page_url'], get_query_var( 'page_no' ) );
		};

		$container['swagger.configuration'] = function ( $c ) {
			$configuration = new Configuration();
			$configuration->setAccessToken( $c['token'] );

			return $configuration;
		};

		$container['swagger.api.shop'] = function ( $c ) {
			return new ShopApi( null, $c['swagger.configuration'] );
		};

		$container['swagger.api.product'] = function ( $c ) {
			return new ProductApi( null, $c['swagger.configuration'] );
		};

		$container['swagger.api.category'] = function ( $c ) {
			return new CategoryApi( null, $c['swagger.configuration'] );
		};

		$this->container = $container;
	}

	/**
	 * src/shortcodes 配下に定義されたショートコードを登録する
	 *
	 * @return void
	 */
	private function register_shortcode() {
		$to_invoker_methodname = function ( $class ) {
			return '_' . str_replace( '/', '_', $class );
		};

		$shortcode_invoker = new Shortcode_Invoker( $this->container );
		$classmap = include( __DIR__ . '/../vendor/composer/autoload_classmap.php' );
		foreach ( $classmap as $class => $path ) {
			if ( strpos( $path, dirname( __DIR__ ) . '/src/shortcodes/' ) !== 0 ) {
				continue;
			}
			add_shortcode(
				call_user_func( [ $class, 'name' ] ),
				[ $shortcode_invoker, $to_invoker_methodname( $class ) ]
			);
		}
	}
}
