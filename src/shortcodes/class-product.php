<?php
namespace ColorMeShop\Shortcodes;

use ColorMeShop\Shortcode_Interface;

/**
 * @see https://shop-pro.jp/manual/menu_06_02_01#tag03
 */
class Product implements Shortcode_Interface {
	/**
	 * @return string
	 */
	public static function name() {
		return 'colormeshop_product';
	}

	/**
	 * 入力のバリデーションと、商品情報を返す各メソッドへの仲介を行う
	 *
	 * @param \Pimple\Container $container
	 * @param array $atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	public static function show( $container, $atts, $content, $tag ) {
		$filtered_atts = shortcode_atts(
			[
				'product_id' => $container['target_id'],
				'data' => null,
			],
			$atts
		);

		if ( empty( $filtered_atts['product_id'] ) || empty( $filtered_atts['data'] ) ) {
			if ( $container['WP_DEBUG_LOG'] ) {
				error_log( 'パラメータが不足しています. atts: ' . json_encode( $filtered_atts ) );
			}
			return '';
		}

		try {
			$container['model.product_api']->fetch( $filtered_atts['product_id'] );
		} catch ( \RuntimeException $e ) {
			if ( $container['WP_DEBUG_LOG'] ) {
				error_log( $e );
			}
			return '';
		}

		if ( method_exists( self::class, '_' . $filtered_atts['data'] ) ) {
			return call_user_func_array(
				[ self::class, '_' . $filtered_atts['data'] ],
				[ $container, $filtered_atts, $content, $tag ]
			);
		}

		return '';
	}

	/**
	 * 商品ID
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _id( $container, $filtered_atts, $content, $tag ) {
		return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->id;
	}

	/**
	 * 商品名
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _name( $container, $filtered_atts, $content, $tag ) {
		return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->name;
	}

    /**
     * 型番
     *
     * @param \Pimple\Container $container
     * @param array $filtered_atts
     * @param string $content
     * @param string $tag
     * @return string
     */
    private static function _model( $container, $filtered_atts, $content, $tag ) {
        return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->model_number;
    }

	/**
	 * 定価
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _price( $container, $filtered_atts, $content, $tag ) {
		return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->price;
	}

	/**
	 * 通常販売価格（割引前の販売価格）
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _regular_price( $container, $filtered_atts, $content, $tag ) {
		return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->sales_price;
	}

	/**
	 * 会員価格
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _members_price( $container, $filtered_atts, $content, $tag ) {
		return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->members_price;
	}

	/**
	 * 単位
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _unit( $container, $filtered_atts, $content, $tag ) {
		return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->unit;
	}

	/**
	 * 重量
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _weight( $container, $filtered_atts, $content, $tag ) {
		return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->weight;
	}

	/**
	 * 簡易説明
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _simple_explain( $container, $filtered_atts, $content, $tag ) {
		return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->simple_expl;
	}

	/**
	 * 商品詳細説明
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _explain( $container, $filtered_atts, $content, $tag ) {
		$p = $container['model.product_api']->fetch( $filtered_atts['product_id'] );
		// モバイルデバイスの場合はスマートフォン用の説明を返す(フィーチャーフォン未対応)
		if ( $p->smartphone_expl !== null && $p->smartphone_expl !== '' && $container['is_mobile'] ) {
			return nl2br($p->smartphone_expl);
		}

		return nl2br($p->expl);
	}

	/**
	 * 個別送料
	 *
	 * @param \Pimple\Container $container
	 * @param array $filtered_atts
	 * @param string $content
	 * @param string $tag
	 * @return string
	 */
	private static function _postage( $container, $filtered_atts, $content, $tag ) {
		return $container['model.product_api']->fetch( $filtered_atts['product_id'] )->delivery_charge;
	}
}
