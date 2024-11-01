'use strict';

(function($) {
  $(document).on('click touch', '.woosl-btn', function(e) {
    e.preventDefault();

    var $btn = $(this);
    var product_id = $btn.data('product_id');
    var variation_id = $btn.data('variation_id');
    var variation = $btn.data('variation');
    var price = $btn.data('price');
    var context = $btn.data('context');

    $btn.removeClass('added').addClass('loading');

    if ($btn.hasClass('woosl-btn-add')) {
      woosl_add_product(product_id, variation_id, variation, price);

      var cart_item_key = $btn.data('cart_item_key');
      var data = {
        action: 'woosl_add',
        cart_item_key: cart_item_key,
        nonce: woosl_vars.nonce,
      };

      $.post(woosl_vars.wc_ajax_url.toString().
          replace('%%endpoint%%', 'woosl_add'), data, function(response) {
        $btn.removeClass('loading').addClass('added');

        if (context === 'woofc') {
          woofc_cart_loading();
          woofc_cart_reload();
        }

        if (response && response.fragments && response.cart_hash) {
          $(document.body).
              trigger('removed_from_cart',
                  [response.fragments, response.cart_hash, $btn]);
        }

        if ($('form.woocommerce-cart-form').length) {
          $(document.body).trigger('wc_update_cart');
        }
      });
    } else if ($btn.hasClass('woosl-btn-remove')) {
      woosl_remove_product(product_id, variation_id);

      var data = {
        action: 'woosl_remove', nonce: woosl_vars.nonce,
      };

      $.post(woosl_vars.wc_ajax_url.toString().
          replace('%%endpoint%%', 'woosl_remove'), data, function(response) {
        $btn.removeClass('loading');

        if (context === 'woofc') {
          woofc_cart_loading();
          woofc_cart_reload();
        }
      });
    }

    // reload table
    woosl_table_reload();
  });

  $(document).on('click touch', '.woosl-btn-all', function(e) {
    e.preventDefault();

    var $btn = $(this);

    $btn.addClass('loading').prop('disabled', true);

    $('.woosl-btn').each(function() {
      var $btn_ = $(this);
      var product_id = $btn_.data('product_id');
      var variation_id = $btn_.data('variation_id');
      var variation = $btn_.data('variation');
      var price = $btn_.data('price');

      woosl_add_product(product_id, variation_id, variation, price);
    });

    var data = {
      action: 'woosl_add_all', nonce: woosl_vars.nonce,
    };

    $.post(woosl_vars.wc_ajax_url.toString().
        replace('%%endpoint%%', 'woosl_add_all'), data, function(response) {
      if ($('form.woocommerce-cart-form').length) {
        $(document.body).trigger('wc_update_cart');
      }
    });

    $btn.removeClass('loading').prop('disabled', false);

    // reload table
    woosl_table_reload();
  });

  $(document).on('click touch', '.woosl_add_to_cart_button', function(e) {
    e.preventDefault();

    var $btn = $(this);
    var $product = $btn.closest('.woosl-product');
    var product_id = $product.data('product_id');
    var variation_id = $product.data('variation_id');
    var variation = $product.data('variation');
    var context = $product.data('context');

    $btn.addClass('loading');

    woosl_remove_product(product_id, variation_id);

    var data = {
      action: 'woosl_add_to_cart',
      product_id: product_id,
      variation_id: variation_id,
      variation: variation,
      nonce: woosl_vars.nonce,
    };

    $.post(woosl_vars.wc_ajax_url.toString().
        replace('%%endpoint%%', 'woosl_add_to_cart'), data, function(response) {
      if (!response) {
        return;
      }

      if (response.error && response.product_url) {
        window.location = response.product_url;
        return;
      }

      if (wc_add_to_cart_params.cart_redirect_after_add === 'yes') {
        window.location = wc_add_to_cart_params.cart_url;
        return;
      }

      $(document.body).
          trigger('added_to_cart',
              [response.fragments, response.cart_hash, $btn]);
      $(document.body).
          trigger('woosl_added_to_cart',
              [response.fragments, response.cart_hash, $btn]);

      $btn.removeClass('loading').addClass('added');

      if (context === 'woofc') {
        woofc_cart_loading();
        woofc_cart_reload();
        return;
      }

      if ($('body').hasClass('woocommerce-account')) {
        woosl_table_reload();
        return;
      }

      if ($('form.woocommerce-cart-form').length) {
        $(document.body).trigger('wc_update_cart');
      } else {
        location.reload();
      }
    });
  });

  $(document).on('click touch', '.woosl_add_all_to_cart_button', function(e) {
    e.preventDefault();

    var $btn = $(this);
    var products = {};

    $btn.addClass('loading').prop('disabled', true);

    if (woosl_get_cookie(woosl_vars.user_key) !== '') {
      products = woosl_get_cookie(woosl_vars.user_key);

      var data = {
        action: 'woosl_add_all_to_cart',
        products: products,
        nonce: woosl_vars.nonce,
      };

      // remove all products
      woosl_set_cookie(woosl_vars.user_key, JSON.stringify({}), 7);

      $.post(woosl_vars.wc_ajax_url.toString().
              replace('%%endpoint%%', 'woosl_add_all_to_cart'), data,
          function(response) {
            if (!response) {
              return;
            }

            if (response.error) {
              location.reload();
            }

            $(document.body).
                trigger('added_to_cart',
                    [response.fragments, response.cart_hash, $btn]);
            $(document.body).
                trigger('woosl_added_to_cart',
                    [response.fragments, response.cart_hash, $btn]);

            if ($('body').hasClass('woocommerce-account')) {
              woosl_table_reload();
              return;
            }

            if ($('form.woocommerce-cart-form').length) {
              $(document.body).trigger('wc_update_cart');
            } else {
              location.reload();
            }
          });
    }

    $btn.removeClass('loading').prop('disabled', false);
  });

  $(document).on('click touch', '.woosl-heading', function(e) {
    if ($(e.target).closest($('.button')).length == 0) {
      $(this).closest('.woosl_table').toggleClass('woosl_table_close');
    }
  });

  function woosl_table_reload() {
    $('.woosl_table').addClass('woosl_table_loading');

    var data = {
      action: 'woosl_load', nonce: woosl_vars.nonce,
    };

    $.post(
        woosl_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'woosl_load'),
        data, function(response) {
          $('.woosl_table').replaceWith(response);
        });
  }

  function woosl_add_product(
      product_id = 0, variation_id = 0, variation = '', price = 0) {
    var products = {};
    var product = {
      product_id: product_id,
      variation_id: variation_id,
      variation: variation,
      price: price,
    };
    var key = product_id.toString() + '_' + variation_id.toString();

    if (woosl_get_cookie(woosl_vars.user_key) !== '') {
      products = JSON.parse(woosl_get_cookie(woosl_vars.user_key));
    }

    products[key] = product;

    woosl_set_cookie(woosl_vars.user_key, JSON.stringify(products), 7);

    $(document).
        trigger('woosl_add_product',
            [product_id, variation_id, variation, price, woosl_vars.user_key]);
  }

  function woosl_remove_product(product_id = 0, variation_id = 0) {
    if (woosl_get_cookie(woosl_vars.user_key) != '') {
      var key = product_id.toString() + '_' + variation_id.toString();
      var products = JSON.parse(woosl_get_cookie(woosl_vars.user_key));

      delete products[key];

      woosl_set_cookie(woosl_vars.user_key, JSON.stringify(products), 7);
    }

    $(document).
        trigger('woosl_remove_product',
            [product_id, variation_id, woosl_vars.user_key]);
  }

  function woosl_set_cookie(cname, cvalue, exdays) {
    var d = new Date();

    d.setTime(d.getTime() + (exdays * 24 * 60 * 60 * 1000));

    var expires = 'expires=' + d.toUTCString();

    document.cookie = cname + '=' + cvalue + '; ' + expires + '; path=/';
  }

  function woosl_get_cookie(cname) {
    var name = cname + '=';
    var ca = document.cookie.split(';');

    for (var i = 0; i < ca.length; i++) {
      var c = ca[i];

      while (c.charAt(0) == ' ') {
        c = c.substring(1);
      }

      if (c.indexOf(name) == 0) {
        return decodeURIComponent(c.substring(name.length, c.length));
      }
    }

    return '';
  }
})(jQuery);