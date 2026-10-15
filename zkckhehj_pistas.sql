-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 15-10-2026 a las 17:59:39
-- Versión del servidor: 11.4.13-MariaDB-cll-lve-log
-- Versión de PHP: 8.4.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `zkckhehj_pistas`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admin_actions_log`
--

CREATE TABLE `admin_actions_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` int(11) NOT NULL COMMENT 'ID de tu tabla usuarios_admin actual',
  `target_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admin_audit_logs`
--

CREATE TABLE `admin_audit_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `target_user_id` int(11) NOT NULL,
  `action_type` varchar(50) DEFAULT 'GRANT_SPIN',
  `details` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `last_failed_attempt` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `auth_identities`
--

CREATE TABLE `auth_identities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `provider` enum('google','email','discord') NOT NULL,
  `provider_user_id` varchar(191) NOT NULL COMMENT 'ID de Google o Email normalizado',
  `provider_email` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `avatars`
--

CREATE TABLE `avatars` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `creator` varchar(80) NOT NULL DEFAULT 'General',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `card_manager_cards`
--

CREATE TABLE `card_manager_cards` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `card_number` varchar(16) NOT NULL COMMENT 'Full card number (13-16 digits)',
  `cvu` varchar(22) NOT NULL DEFAULT '' COMMENT 'CVU / CBU of the card',
  `cvc` varchar(4) NOT NULL DEFAULT '' COMMENT 'CVC / CVV security code (3-4 digits)',
  `expiration_date` varchar(5) NOT NULL DEFAULT '' COMMENT 'Expiration date (MM/YY)',
  `card_type` enum('dollarapp','astropay') NOT NULL DEFAULT 'dollarapp' COMMENT 'Card provider type',
  `cardholder_name` varchar(100) NOT NULL COMMENT 'Cardholder full name (titular)',
  `group_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK opcional → card_manager_groups.id',
  `card_sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Orden manual dentro de su grupo',
  `uses_remaining` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `last_used_at` datetime DEFAULT NULL COMMENT 'UTC timestamp of last use',
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `card_manager_groups`
--

CREATE TABLE `card_manager_groups` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL COMMENT 'Nombre visible del grupo',
  `card_type` enum('dollarapp','astropay') NOT NULL DEFAULT 'dollarapp',
  `cardholder_name` varchar(100) NOT NULL DEFAULT '',
  `cvu` varchar(22) NOT NULL DEFAULT '',
  `bg_color` varchar(7) DEFAULT NULL COMMENT 'Hex background color for group section',
  `card_color` varchar(7) DEFAULT NULL COMMENT 'Optional hex color for cards in this group',
  `resets_remaining` smallint(5) UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Remaining group resets (DollarApp only, starts counting at 3 cards)',
  `monthly_resets_used` smallint(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Resets used in the current 30-day window (max 10)',
  `window_started_at` datetime DEFAULT NULL COMMENT 'UTC timestamp when the current 30-day reset window started (NULL = not yet started)',
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Orden en el dropdown/drawer',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `coins`
--

CREATE TABLE `coins` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `balance` decimal(10,2) DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `coin_transactions`
--

CREATE TABLE `coin_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL COMMENT 'Positivo=ingreso, Negativo=gasto',
  `reason` enum('cashback','daily_spin','coupon_bonus','gift','manual','raffle_win') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'ej: order_id',
  `reference_id` varchar(191) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `collage_backgrounds`
--

CREATE TABLE `collage_backgrounds` (
  `id` char(36) NOT NULL,
  `name` varchar(140) NOT NULL,
  `url` longtext NOT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updatedAt` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion_tienda`
--

CREATE TABLE `configuracion_tienda` (
  `clave` varchar(50) NOT NULL,
  `valor` text DEFAULT NULL,
  `descripcion` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cupones`
--

CREATE TABLE `cupones` (
  `id` int(11) NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `tipo_descuento` enum('porcentaje','fijo') NOT NULL,
  `valor_descuento` decimal(10,2) NOT NULL,
  `gasto_minimo` decimal(10,2) DEFAULT 0.00,
  `descuento_maximo` decimal(10,2) DEFAULT NULL,
  `limite_usos` int(11) DEFAULT NULL,
  `usos_actuales` int(11) DEFAULT 0,
  `limite_por_usuario` int(11) DEFAULT 1,
  `limite_por_ip` int(11) DEFAULT NULL,
  `limite_por_whatsapp` int(11) DEFAULT NULL,
  `fecha_inicio` datetime DEFAULT NULL,
  `fecha_expiracion` datetime DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `requiere_login` tinyint(1) NOT NULL DEFAULT 0,
  `requiere_verificacion` tinyint(1) NOT NULL DEFAULT 0,
  `advertencia_activa` tinyint(1) NOT NULL DEFAULT 0,
  `advertencia_texto` text DEFAULT NULL,
  `permite_pago_automatico` tinyint(1) NOT NULL DEFAULT 0,
  `compras_minimas_requeridas` int(11) DEFAULT 0,
  `cantidad_minima_compra` int(11) NOT NULL DEFAULT 1,
  `aplicar_a_un_solo_item` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = Aplica a todos los items elegibles, 1 = Aplica solo al primer item elegible del carrito',
  `requiere_todos_los_productos` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = OR (basta un producto), 1 = AND (todos los productos requeridos)',
  `tiempo_limite_pago_minutos` int(11) DEFAULT NULL,
  `audio_url` varchar(500) DEFAULT NULL COMMENT 'URL del audio mp3/wav (Cloudinary o Local)',
  `imagen_url` varchar(500) DEFAULT NULL COMMENT 'URL de la imagen o sticker (Cloudinary o Local)',
  `modo_efecto` varchar(50) DEFAULT 'ninguno' COMMENT 'Ej: ninguno, lluvia, imagen_centro, vibracion',
  `msg_exito` text DEFAULT NULL COMMENT 'Mensaje que sale al aplicar el cupón correctamente',
  `msg_error` text DEFAULT NULL COMMENT 'Mensaje personalizado cuando da error o está agotado',
  `msg_confirmacion` text DEFAULT NULL COMMENT 'Mensaje para el correo o página de gracias tras la compra',
  `quiz_config` longtext DEFAULT NULL COMMENT 'Configuración JSON del Quiz'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cupones_reglas_aplicabilidad`
--

CREATE TABLE `cupones_reglas_aplicabilidad` (
  `id` int(11) NOT NULL,
  `cupon_id` int(11) NOT NULL,
  `tipo_regla` enum('global','categoria_estatica','categoria_api','seccion_tienda','producto_estatico','producto_especifico','global_sin_estaticos') NOT NULL,
  `valor_regla` varchar(255) NOT NULL COMMENT 'ID del producto, nombre de categoría, o nombre de sección'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cupones_usos_destinatario`
--

CREATE TABLE `cupones_usos_destinatario` (
  `id` int(11) NOT NULL,
  `cupon_id` int(11) NOT NULL,
  `destinatario_epic_id` varchar(255) NOT NULL,
  `pedido_id` int(11) NOT NULL,
  `fecha_uso` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cupones_usos_tracking`
--

CREATE TABLE `cupones_usos_tracking` (
  `id` int(11) NOT NULL,
  `cupon_id` int(11) NOT NULL,
  `pedido_id` int(11) NOT NULL,
  `ip_usuario` varchar(45) NOT NULL,
  `whatsapp_usuario` varchar(20) DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `fecha_uso` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `daily_discounts`
--

CREATE TABLE `daily_discounts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `epic_nick_snapshot` varchar(100) NOT NULL,
  `item_id` varchar(255) NOT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `item_image_url` varchar(500) DEFAULT NULL,
  `discount_percent` int(11) DEFAULT 20,
  `is_flash_prize` tinyint(1) DEFAULT 0,
  `spin_source` enum('daily_free','bonus_gift','paid_coins','raffle_prize') DEFAULT 'daily_free',
  `coins_spent` int(11) DEFAULT 0,
  `status` enum('active','used','expired') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `raffle_id` int(11) DEFAULT NULL,
  `coupon_value_coins` decimal(12,2) DEFAULT NULL,
  `spin_mode` varchar(16) DEFAULT NULL,
  `filter_key` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `daily_special_prizes_pool`
--

CREATE TABLE `daily_special_prizes_pool` (
  `id` int(11) NOT NULL,
  `active_date` date NOT NULL,
  `prize_type` enum('gold_100','silver_50','bronze_40') NOT NULL,
  `max_qty` int(11) DEFAULT 1,
  `delivered_qty` int(11) DEFAULT 0,
  `rules_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rules_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `daily_spin_config`
--

CREATE TABLE `daily_spin_config` (
  `id` int(11) NOT NULL,
  `event_date` date NOT NULL,
  `mode` enum('global','forced_theme','dual_choice') DEFAULT 'global',
  `theme_name` varchar(100) DEFAULT NULL,
  `filter_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`filter_rules`)),
  `start_time_override` datetime DEFAULT NULL,
  `flash_offer_enabled` tinyint(1) DEFAULT 0,
  `flash_offer_limit` int(11) DEFAULT 30,
  `flash_offer_claimed` int(11) DEFAULT 0,
  `flash_offer_percent` int(11) DEFAULT 25,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `paid_spins_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0: Desactivado, 1: Activo',
  `paid_spins_limit` int(11) NOT NULL DEFAULT 3 COMMENT 'Máximo de tiros pagados por día',
  `paid_spin_price` int(11) NOT NULL DEFAULT 50 COMMENT 'Costo en monedas por cada tiro extra',
  `normal_discount_percent` int(11) NOT NULL DEFAULT 20,
  `no_tracks_discount_percent` int(11) NOT NULL DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `daily_spin_filter_config`
--

CREATE TABLE `daily_spin_filter_config` (
  `filter_key` varchar(64) NOT NULL,
  `label` varchar(80) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `discount_percent` int(11) NOT NULL DEFAULT 5,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `descuentos_automaticos`
--

CREATE TABLE `descuentos_automaticos` (
  `id` int(11) NOT NULL,
  `offer_id` varchar(255) NOT NULL,
  `id_regla` int(11) NOT NULL COMMENT 'FK a la tabla ofertas_reglas',
  `fecha_generacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `descuentos_manuales`
--

CREATE TABLE `descuentos_manuales` (
  `id` int(11) NOT NULL,
  `offer_id` varchar(255) NOT NULL,
  `nombre_articulo` varchar(255) NOT NULL,
  `tipo_descuento` varchar(20) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `fecha_inicio` datetime DEFAULT NULL,
  `fecha_expiracion` datetime DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `descuentos_reglas_aplicabilidad`
--

CREATE TABLE `descuentos_reglas_aplicabilidad` (
  `id` int(11) NOT NULL,
  `descuento_id` int(11) NOT NULL,
  `tipo_regla` varchar(50) NOT NULL,
  `valor_regla` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalles_pedido`
--

CREATE TABLE `detalles_pedido` (
  `id` int(11) NOT NULL,
  `pedido_id` int(11) NOT NULL,
  `producto_id_str` varchar(255) NOT NULL COMMENT 'ID único del producto, de la API o estático',
  `nombre_producto` varchar(255) NOT NULL,
  `imagen_producto_url` text DEFAULT NULL COMMENT 'URL de la imagen del producto al momento de la compra',
  `categoria_producto` varchar(100) DEFAULT 'General' COMMENT 'Categoría del producto al momento de la compra',
  `precio_final` decimal(10,2) NOT NULL,
  `precio_base` decimal(10,2) DEFAULT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `tipo_producto` varchar(50) DEFAULT NULL,
  `fecha_expiracion_item` datetime DEFAULT NULL COMMENT 'Para items de la tienda diaria de Fortnite',
  `v_bucks` int(11) DEFAULT 0 COMMENT 'Precio original en moneda del juego (finalPrice)',
  `color_1` varchar(10) DEFAULT NULL COMMENT 'Color primario (Hex RGBA 8 chars)',
  `color_2` varchar(10) DEFAULT NULL COMMENT 'Color secundario',
  `color_3` varchar(10) DEFAULT NULL COMMENT 'Color terciario',
  `section_name` varchar(255) DEFAULT NULL,
  `store_rank` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalles_pedido_destinatarios`
--

CREATE TABLE `detalles_pedido_destinatarios` (
  `id` int(11) NOT NULL,
  `detalle_pedido_id` int(11) NOT NULL,
  `nickname_destinatario` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `dl_api_field_map`
--

CREATE TABLE `dl_api_field_map` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scope` enum('data','entry') NOT NULL DEFAULT 'data',
  `source_key` varchar(150) NOT NULL,
  `canonical_key` varchar(150) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `nota` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `dl_custom_entries`
--

CREATE TABLE `dl_custom_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `offer_id` varchar(255) NOT NULL,
  `entry_json` mediumtext NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `dl_event_log`
--

CREATE TABLE `dl_event_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `severity` enum('info','warning','error','critical') NOT NULL DEFAULT 'info',
  `event_type` varchar(64) NOT NULL,
  `message` varchar(500) NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `dl_shop_overrides`
--

CREATE TABLE `dl_shop_overrides` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `offer_id` varchar(255) NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `field_value` mediumtext DEFAULT NULL,
  `value_type` enum('string','number','json','color') NOT NULL DEFAULT 'string',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `nota` varchar(255) DEFAULT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `dl_state`
--

CREATE TABLE `dl_state` (
  `k` varchar(100) NOT NULL,
  `v` mediumtext DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `email_login_tokens`
--

CREATE TABLE `email_login_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(150) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `purpose` enum('login','confirm_email') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `excluded_ips`
--

CREATE TABLE `excluded_ips` (
  `id` int(11) NOT NULL,
  `ip_hash` varchar(255) NOT NULL,
  `description` varchar(100) DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `game_card_visuals`
--

CREATE TABLE `game_card_visuals` (
  `id` int(11) NOT NULL,
  `card_key` varchar(50) NOT NULL,
  `background_image_url` text DEFAULT NULL,
  `background_opacity` decimal(3,2) NOT NULL DEFAULT 1.00,
  `background_color` varchar(30) DEFAULT NULL,
  `bg_position_x` int(11) NOT NULL DEFAULT 50,
  `bg_position_y` int(11) NOT NULL DEFAULT 50,
  `bg_scale` int(11) NOT NULL DEFAULT 100,
  `texts_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`texts_config`)),
  `media_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`media_config`)),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ips_bloqueadas`
--

CREATE TABLE `ips_bloqueadas` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `motivo` text DEFAULT NULL,
  `fecha_bloqueo` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_pedidos`
--

CREATE TABLE `logs_pedidos` (
  `id` int(11) NOT NULL,
  `pedido_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `log_regalos_completados`
--

CREATE TABLE `log_regalos_completados` (
  `id` int(11) NOT NULL,
  `pedido_id` int(11) NOT NULL,
  `fecha_envio` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `dedupe_key` varchar(191) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ofertas_reglas`
--

CREATE TABLE `ofertas_reglas` (
  `id` int(11) NOT NULL,
  `nombre_regla` varchar(100) NOT NULL,
  `estrategia_seleccion` varchar(50) NOT NULL DEFAULT 'palabra_clave',
  `tipo_articulo` varchar(50) NOT NULL COMMENT 'Palabra clave a buscar en displayType, ej: Lote, Atuendo, Gesto',
  `cantidad_a_elegir` int(11) NOT NULL DEFAULT 1,
  `porcentaje_descuento` int(11) NOT NULL DEFAULT 15,
  `etiqueta_texto` varchar(50) NOT NULL DEFAULT 'OFERTA',
  `etiqueta_color_fondo` varchar(7) NOT NULL DEFAULT '#22c55e',
  `etiqueta_color_texto` varchar(7) NOT NULL DEFAULT '#ffffff',
  `precio_oferta_color` varchar(7) NOT NULL DEFAULT '#22c55e',
  `etiqueta_posicion` int(1) NOT NULL DEFAULT 1 COMMENT '1=Arriba, 2=Abajo',
  `orden` int(11) NOT NULL DEFAULT 99,
  `activo` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ofertas_tipos_articulos`
--

CREATE TABLE `ofertas_tipos_articulos` (
  `id` int(11) NOT NULL,
  `nombre_tipo` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `page_visits`
--

CREATE TABLE `page_visits` (
  `id` int(11) NOT NULL,
  `ip_address_hash` varchar(255) NOT NULL,
  `visit_timestamp` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pedidos`
--

CREATE TABLE `pedidos` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `codigo_pedido` varchar(20) NOT NULL,
  `email_cliente` varchar(255) DEFAULT NULL,
  `whatsapp_discord_cliente` varchar(100) DEFAULT NULL,
  `nickname_fortnite_cliente` varchar(100) NOT NULL,
  `es_regalo` tinyint(1) NOT NULL DEFAULT 0,
  `nickname_destinatario` varchar(100) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `monto_descuento` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_pagar` decimal(10,2) NOT NULL,
  `cupon_usado_codigo` varchar(50) DEFAULT NULL,
  `metodo_pago` enum('yape','transfer','izipay','paypal','crypto','otro') NOT NULL,
  `estado` enum('pendiente','pagado','completado','cancelado','reembolso_pendiente') NOT NULL DEFAULT 'pendiente',
  `estado_pago_cliente` enum('no_notificado','notificado_pendiente_revision') NOT NULL DEFAULT 'no_notificado',
  `ip_cliente` varchar(45) DEFAULT NULL,
  `notas_admin` text DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `fecha_notificacion_pago` datetime DEFAULT NULL,
  `fecha_pago` datetime DEFAULT NULL,
  `fecha_completado` datetime DEFAULT NULL,
  `fecha_reserva_expira` datetime DEFAULT NULL,
  `fecha_expiracion_oferta` datetime DEFAULT NULL,
  `coins_redeemed` int(11) DEFAULT 0,
  `coins_value_money` decimal(10,2) DEFAULT 0.00,
  `recipient_mode` enum('self','friend','multi') DEFAULT 'self',
  `counts_toward_verification` tinyint(1) DEFAULT 0 COMMENT 'Solo true si es self y cumple reglas',
  `cashback_mode` enum('none','base','verified') NOT NULL DEFAULT 'none',
  `cashback_earned` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stats_applied_at` datetime DEFAULT NULL COMMENT 'Evita sumar stats 2 veces',
  `es_reserva` tinyint(1) NOT NULL DEFAULT 0,
  `cancelado_auto` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pistas_musicales`
--

CREATE TABLE `pistas_musicales` (
  `id` int(11) NOT NULL,
  `nombre_pista` varchar(255) NOT NULL,
  `youtube_id` varchar(255) DEFAULT NULL,
  `start_time` int(11) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cosmetic_id` varchar(120) DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `submission_id` bigint(20) UNSIGNED DEFAULT NULL,
  `media_type` enum('youtube','audio','video') NOT NULL DEFAULT 'youtube',
  `media_file` varchar(255) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verified_by` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos_estaticos`
--

CREATE TABLE `productos_estaticos` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `precio_base` decimal(10,2) NOT NULL,
  `precio_oferta` decimal(10,2) DEFAULT NULL,
  `tipo_oferta` enum('precio_fijo','porcentaje') DEFAULT 'precio_fijo',
  `oferta_fecha_expiracion` datetime DEFAULT NULL,
  `urgencia_horas` int(11) NOT NULL DEFAULT 24,
  `etiqueta_posicion` int(1) NOT NULL DEFAULT 1,
  `imagen_url` varchar(512) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `stock_cantidad` int(11) DEFAULT -1,
  `categoria` varchar(100) NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 99,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_expiracion` datetime DEFAULT NULL,
  `etiqueta_texto` varchar(50) DEFAULT NULL,
  `etiqueta_color_fondo` varchar(7) DEFAULT '#22c55e',
  `etiqueta_color_texto` varchar(7) DEFAULT '#ffffff',
  `precio_oferta_color` varchar(7) DEFAULT '#22c55e',
  `timer_color_fondo` varchar(7) DEFAULT '#18181b',
  `timer_color_texto` varchar(7) DEFAULT '#ffffff',
  `etiqueta_fecha_fin` datetime DEFAULT NULL,
  `orden_categoria` int(11) DEFAULT 99,
  `monedas_otorgadas` int(11) DEFAULT 0 COMMENT 'Cantidad de monedas que da este pack al comprarlo',
  `permitir_canje_monedas` tinyint(1) DEFAULT 1,
  `requiere_nickname` tinyint(1) DEFAULT 0,
  `dar_cashback` tinyint(1) DEFAULT 1,
  `activar_tutorial` tinyint(1) NOT NULL DEFAULT 0,
  `mensaje_tutorial` text DEFAULT NULL,
  `modo_reserva` tinyint(1) NOT NULL DEFAULT 0,
  `mensaje_reserva` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `product_clicks`
--

CREATE TABLE `product_clicks` (
  `id` int(11) NOT NULL,
  `offer_id` varchar(255) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `ip_address_hash` varchar(64) NOT NULL,
  `click_timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `profile_change_requests`
--

CREATE TABLE `profile_change_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `field_type` enum('game_username','public_display_name','whatsapp_phone') NOT NULL,
  `old_value` varchar(191) DEFAULT NULL,
  `new_value` varchar(191) NOT NULL,
  `status` enum('pending','approved','rejected','canceled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `admin_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffles`
--

CREATE TABLE `raffles` (
  `raffle_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `status` enum('scheduled','open','processing','locking','waiting_for_winner','completed','cancelled') DEFAULT 'scheduled',
  `execution_mode` enum('standard_queue','fast_batch') DEFAULT 'standard_queue',
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `replay_minutes` int(11) DEFAULT 1,
  `prize_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`prize_config`)),
  `main_animation` enum('roulette','slot') DEFAULT 'roulette' COMMENT 'Ruleta o Slot para el sorteo general',
  `entry_cost` decimal(10,2) DEFAULT 0.00,
  `is_pot_enabled` tinyint(1) DEFAULT 0,
  `pot_allocation_percent` int(11) DEFAULT 100 COMMENT '% del costo que va al bote',
  `current_pot` decimal(12,2) DEFAULT 0.00,
  `winners_count` int(11) DEFAULT 1,
  `burn_count` int(11) DEFAULT 0,
  `max_participants` int(11) DEFAULT 0 COMMENT '0 = Sin limite',
  `requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`requirements`)),
  `allow_cashback` tinyint(1) DEFAULT 0,
  `cashback_percent` int(11) DEFAULT 50 COMMENT '% del valor base si elige monedas',
  `allow_double_or_nothing` tinyint(1) DEFAULT 0,
  `gamble_animation` enum('roulette','coin','slot') DEFAULT 'roulette' COMMENT 'Minijuego para doblar',
  `allow_winner_animation_choice` tinyint(1) DEFAULT 1 COMMENT '1=Usuario elige animación (Moneda/Slot/Ruleta), 0=Forzado a usar gamble_animation por defecto',
  `audio_config` text DEFAULT NULL,
  `decision_timeout` int(11) DEFAULT 60 COMMENT 'Segundos para decidir',
  `server_seed_secret` varchar(255) DEFAULT NULL,
  `server_seed_hash` varchar(64) DEFAULT NULL,
  `bsc_block_target` int(11) DEFAULT NULL,
  `bsc_block_hash` varchar(66) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `animation_start_at` datetime DEFAULT NULL,
  `decision_phase_reached_at` datetime DEFAULT NULL,
  `allow_pre_choice` tinyint(1) NOT NULL DEFAULT 1,
  `is_private` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_bans`
--

CREATE TABLE `raffle_bans` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ban_type` enum('next_n_raffles','until_date','permanent') NOT NULL DEFAULT 'permanent',
  `raffles_remaining` int(11) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `ip_snapshot` varchar(64) NOT NULL DEFAULT '',
  `fingerprint_snapshot` varchar(64) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `lifted_at` datetime DEFAULT NULL,
  `lifted_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_early_bird_rewards`
--

CREATE TABLE `raffle_early_bird_rewards` (
  `id` int(11) NOT NULL,
  `raffle_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ticket_index` int(11) NOT NULL,
  `position` int(11) NOT NULL,
  `mode` varchar(16) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL,
  `settled_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_entry_signals`
--

CREATE TABLE `raffle_entry_signals` (
  `id` int(11) NOT NULL,
  `raffle_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(64) NOT NULL DEFAULT '',
  `fingerprint` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_invitations`
--

CREATE TABLE `raffle_invitations` (
  `id` int(11) NOT NULL,
  `raffle_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `added_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_manual_purchases`
--

CREATE TABLE `raffle_manual_purchases` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `purchase_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `channel` enum('whatsapp','messenger','manual','other') NOT NULL DEFAULT 'manual',
  `note` varchar(255) NOT NULL DEFAULT '',
  `approved_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `via` enum('regalo','cuenta') DEFAULT NULL,
  `subcategory` enum('club','pavos','packs') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_participants`
--

CREATE TABLE `raffle_participants` (
  `id` int(11) NOT NULL,
  `raffle_id` int(11) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `ticket_index` int(11) NOT NULL,
  `entry_fee_paid` decimal(10,2) DEFAULT 0.00 COMMENT 'Cuánto pagó al momento de entrar',
  `joined_at` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `review_status` enum('active','under_review','expelled') NOT NULL DEFAULT 'active',
  `declared` tinyint(1) NOT NULL DEFAULT 0,
  `pre_chosen_prize` enum('original','cashback','gamble') DEFAULT NULL,
  `pre_gamble_anim` enum('coin','slot','roulette') DEFAULT NULL,
  `pre_gamble_side` enum('heads','tails','red','black') DEFAULT NULL,
  `pre_pool_choices` text DEFAULT NULL,
  `used_welcome_ticket` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_snapshots`
--

CREATE TABLE `raffle_snapshots` (
  `raffle_id` int(11) NOT NULL,
  `snapshot_json` mediumtext NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_strikes`
--

CREATE TABLE `raffle_strikes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `raffle_id` int(11) NOT NULL DEFAULT 0,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_viewers`
--

CREATE TABLE `raffle_viewers` (
  `raffle_id` int(11) NOT NULL,
  `viewer_key` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL DEFAULT 0,
  `last_seen` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_welcome_grants`
--

CREATE TABLE `raffle_welcome_grants` (
  `user_id` int(11) NOT NULL,
  `granted_by` int(11) NOT NULL DEFAULT 0,
  `granted_by_name` varchar(64) NOT NULL DEFAULT '',
  `note` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_welcome_tickets`
--

CREATE TABLE `raffle_welcome_tickets` (
  `user_id` int(11) NOT NULL,
  `raffle_id` int(11) NOT NULL,
  `ticket_index` int(11) NOT NULL DEFAULT 0,
  `qualifying_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `threshold_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `value_mode` varchar(16) NOT NULL DEFAULT 'max',
  `used_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_whitelist`
--

CREATE TABLE `raffle_whitelist` (
  `id` int(11) NOT NULL,
  `raffle_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL DEFAULT '',
  `added_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `raffle_winners`
--

CREATE TABLE `raffle_winners` (
  `id` int(11) NOT NULL,
  `raffle_id` int(11) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `position` int(11) NOT NULL,
  `choice` enum('pending','original','cashback','gamble_pending','double_won','double_lost','exchange','burned') DEFAULT 'pending',
  `coin_choice` enum('heads','tails','red','black') DEFAULT NULL,
  `selected_animation_mode` enum('roulette','coin','slot') DEFAULT NULL COMMENT 'Animación elegida por el usuario para el Doble o Nada',
  `gamble_server_seed` varchar(64) DEFAULT NULL,
  `gamble_server_hash` varchar(64) DEFAULT NULL,
  `decision_expires_at` datetime DEFAULT NULL,
  `gamble_block_target` bigint(20) DEFAULT NULL COMMENT 'Bloque BSC futuro asignado para la ruleta',
  `gamble_block_hash` varchar(66) DEFAULT NULL COMMENT 'Hash resultante del bloque',
  `gamble_result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Log del resultado: {wager, outcome}' CHECK (json_valid(`gamble_result`)),
  `prize_sent` tinyint(1) DEFAULT 0,
  `generated_order_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `gamble_resolved_at` datetime DEFAULT NULL,
  `pre_apply_expires_at` datetime DEFAULT NULL,
  `pre_apply_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `delivery_status` enum('pending','delivered') NOT NULL DEFAULT 'pending',
  `delivery_proof_image` varchar(255) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `delivered_by` int(11) DEFAULT NULL,
  `delivery_note` varchar(255) DEFAULT NULL,
  `prize_state` varchar(24) DEFAULT NULL,
  `final_prize` text DEFAULT NULL,
  `prize_selected_at` datetime DEFAULT NULL,
  `cashback_credited_coins` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `registros_actividad`
--

CREATE TABLE `registros_actividad` (
  `id` int(11) NOT NULL,
  `accion` varchar(255) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `secciones_fondos`
--

CREATE TABLE `secciones_fondos` (
  `id` int(10) UNSIGNED NOT NULL,
  `section_key` varchar(160) NOT NULL,
  `section_name` varchar(190) NOT NULL DEFAULT '',
  `image_url` text NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `persistente` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `site_settings`
--

CREATE TABLE `site_settings` (
  `key_name` varchar(50) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_attachments`
--

CREATE TABLE `tareas_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `mision_id` bigint(20) UNSIGNED NOT NULL,
  `stored_name` varchar(120) NOT NULL COMMENT 'Nombre en disco (uploads/)',
  `original_name` varchar(180) NOT NULL,
  `mime` varchar(100) NOT NULL DEFAULT '',
  `size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_changes`
--

CREATE TABLE `tareas_changes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scope` varchar(20) NOT NULL COMMENT 'Colección afectada: ideas/misiones/colaboradores/tags/all',
  `actor` varchar(64) NOT NULL DEFAULT '' COMMENT 'Client-Id que originó el cambio (para suprimir el eco)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_checklist_items`
--

CREATE TABLE `tareas_checklist_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `mision_id` bigint(20) UNSIGNED NOT NULL,
  `content` varchar(255) NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_colaboradores`
--

CREATE TABLE `tareas_colaboradores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(60) NOT NULL COMMENT 'Nombre visible del colaborador',
  `color` varchar(7) NOT NULL DEFAULT '#6c63ff' COMMENT 'Color hex del avatar',
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_comments`
--

CREATE TABLE `tareas_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `mision_id` bigint(20) UNSIGNED NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_ideas`
--

CREATE TABLE `tareas_ideas` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `content` text NOT NULL COMMENT 'Texto libre de la idea',
  `accent` varchar(20) NOT NULL DEFAULT 'violet' COMMENT 'Tema de color de la tarjeta',
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','converted','archived') NOT NULL DEFAULT 'active',
  `converted_mision_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Misión generada al convertir',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_idea_tags`
--

CREATE TABLE `tareas_idea_tags` (
  `idea_id` bigint(20) UNSIGNED NOT NULL,
  `tag_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_misiones`
--

CREATE TABLE `tareas_misiones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pendiente','en_progreso','completada','archivada') NOT NULL DEFAULT 'pendiente',
  `priority` enum('baja','media','alta') NOT NULL DEFAULT 'media',
  `accent` varchar(20) NOT NULL DEFAULT 'violet',
  `due_date` date DEFAULT NULL,
  `recurrence` enum('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
  `colaborador_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Colaborador asignado (NULL = sin asignar)',
  `origin_idea_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Idea de origen si nació de una conversión',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_mision_tags`
--

CREATE TABLE `tareas_mision_tags` (
  `mision_id` bigint(20) UNSIGNED NOT NULL,
  `tag_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_tags`
--

CREATE TABLE `tareas_tags` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(40) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'violet' COMMENT 'Token de acento o color hex',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_templates`
--

CREATE TABLE `tareas_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL COMMENT 'Nombre de la plantilla',
  `title` varchar(160) NOT NULL DEFAULT '',
  `description` text DEFAULT NULL,
  `priority` enum('baja','media','alta') NOT NULL DEFAULT 'media',
  `accent` varchar(20) NOT NULL DEFAULT 'violet',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tareas_template_items`
--

CREATE TABLE `tareas_template_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `template_id` bigint(20) UNSIGNED NOT NULL,
  `content` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tarjetas_v2_cards`
--

CREATE TABLE `tarjetas_v2_cards` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `profile_id` bigint(20) UNSIGNED NOT NULL,
  `number_encrypted` text NOT NULL,
  `expiry` varchar(7) NOT NULL,
  `cvv_encrypted` text NOT NULL,
  `uses_remaining` int(11) NOT NULL DEFAULT 5,
  `cooldown_until` datetime DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tarjetas_v2_emitters`
--

CREATE TABLE `tarjetas_v2_emitters` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `color` char(7) NOT NULL DEFAULT '#6c63ff',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tarjetas_v2_preferences`
--

CREATE TABLE `tarjetas_v2_preferences` (
  `id` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `selected_preset` varchar(80) NOT NULL DEFAULT 'split',
  `custom_presets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom_presets`)),
  `theme` enum('system','dark','light') NOT NULL DEFAULT 'system',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tarjetas_v2_profiles`
--

CREATE TABLE `tarjetas_v2_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `emitter_id` bigint(20) UNSIGNED NOT NULL,
  `bank` varchar(100) NOT NULL,
  `device` varchar(100) NOT NULL,
  `holder` varchar(120) NOT NULL,
  `cvu` varchar(34) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `account_password` text DEFAULT NULL,
  `location` varchar(190) DEFAULT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `color` char(7) NOT NULL DEFAULT '#6c63ff',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `public_code` varchar(20) NOT NULL COMMENT 'Codigo unico compartible',
  `email` varchar(150) DEFAULT NULL,
  `game_username` varchar(100) NOT NULL,
  `game_username_norm` varchar(100) NOT NULL COMMENT 'Lowercase + trim',
  `game_username_updated_at` timestamp NULL DEFAULT NULL,
  `game_name_mode` enum('free','review') DEFAULT 'free',
  `game_username_locked` tinyint(1) DEFAULT 0,
  `public_display_name` varchar(100) DEFAULT NULL,
  `public_display_name_norm` varchar(100) DEFAULT NULL,
  `public_display_name_updated_at` timestamp NULL DEFAULT NULL,
  `public_name_mode` enum('free','review') DEFAULT 'free',
  `public_name_locked` tinyint(1) DEFAULT 0,
  `whatsapp_phone_e164` varchar(20) DEFAULT NULL,
  `phone_updated_at` timestamp NULL DEFAULT NULL,
  `phone_mode` enum('review') DEFAULT 'review',
  `whatsapp_locked` tinyint(1) DEFAULT 0,
  `account_status` enum('active','suspended','banned') DEFAULT 'active',
  `ban_until` timestamp NULL DEFAULT NULL,
  `banned_at` timestamp NULL DEFAULT NULL,
  `ban_reason` text DEFAULT NULL,
  `benefits_enabled` tinyint(1) DEFAULT 1,
  `is_verified` tinyint(1) DEFAULT 0,
  `verification_rejected` tinyint(1) NOT NULL DEFAULT 0,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` varchar(50) DEFAULT NULL,
  `verified_notes` text DEFAULT NULL,
  `avatar_id` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `avatar_updated_at` timestamp NULL DEFAULT NULL,
  `country_iso2` varchar(5) DEFAULT NULL,
  `country_updated_at` datetime DEFAULT NULL,
  `bonus_spins` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users_tutoriales_vistos`
--

CREATE TABLE `users_tutoriales_vistos` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `categoria` varchar(100) NOT NULL,
  `fecha_visto` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_nicks`
--

CREATE TABLE `user_nicks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `platform` enum('psn','xbox','nintendo_switch') NOT NULL,
  `nickname` varchar(100) NOT NULL,
  `nickname_norm` varchar(100) NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL COMMENT 'Para controlar el cooldown de 30 dias',
  `is_locked` tinyint(1) DEFAULT 0 COMMENT '1 = Solo admin puede cambiarlo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_stats`
--

CREATE TABLE `user_stats` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `orders_count_completed_self` int(11) DEFAULT 0,
  `total_spent_completed_self` decimal(10,2) DEFAULT 0.00,
  `orders_count_total` int(11) DEFAULT 0,
  `total_spent_total` decimal(10,2) DEFAULT 0.00,
  `last_order_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_tokens`
--

CREATE TABLE `user_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `selector` char(24) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `verification_requests`
--

CREATE TABLE `verification_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `calculated_spend` decimal(10,2) DEFAULT 0.00,
  `game_username_snapshot` varchar(100) DEFAULT NULL,
  `whatsapp_snapshot` varchar(20) DEFAULT NULL,
  `status` enum('pending','approved','rejected','canceled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `admin_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `videos_objetos`
--

CREATE TABLE `videos_objetos` (
  `id` int(10) UNSIGNED NOT NULL,
  `cosmetic_id` varchar(191) NOT NULL,
  `style_key` varchar(64) NOT NULL DEFAULT '',
  `style_name` varchar(120) DEFAULT NULL,
  `nombre_objeto` varchar(255) DEFAULT NULL,
  `video_url` text NOT NULL,
  `origen` enum('url','archivo') NOT NULL DEFAULT 'url',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `videos_tienda`
--

CREATE TABLE `videos_tienda` (
  `id` varchar(100) NOT NULL,
  `nombre` varchar(255) DEFAULT NULL,
  `tipo_principal` varchar(100) DEFAULT NULL,
  `video_url` text DEFAULT NULL,
  `tipo_video` varchar(20) DEFAULT NULL,
  `styles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`styles`)),
  `products` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`products`)),
  `last_checked` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `video_bans`
--

CREATE TABLE `video_bans` (
  `user_id` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `banned_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `video_reports`
--

CREATE TABLE `video_reports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `pista_id` int(11) NOT NULL,
  `submission_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reporter_id` int(11) NOT NULL,
  `reason` varchar(50) NOT NULL,
  `status` enum('open','upheld','dismissed') NOT NULL DEFAULT 'open',
  `reward_amount` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `reward_paid` tinyint(1) NOT NULL DEFAULT 0,
  `reviewed_by` varchar(100) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `active_key` int(11) GENERATED ALWAYS AS (if(`status` = 'open',`pista_id`,NULL)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `video_report_reasons`
--

CREATE TABLE `video_report_reasons` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `label` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `video_submissions`
--

CREATE TABLE `video_submissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `cosmetic_id` varchar(120) NOT NULL DEFAULT '',
  `track_name` varchar(255) NOT NULL,
  `youtube_id` varchar(20) NOT NULL,
  `start_seconds` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `status` enum('published','rejected','replaced','deleted') NOT NULL DEFAULT 'published',
  `reward_amount` int(10) UNSIGNED NOT NULL DEFAULT 20,
  `video_title` varchar(255) DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `reviewed_by` varchar(100) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `edit_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `edited_at` datetime DEFAULT NULL,
  `reward_reversed` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `video_submission_edits`
--

CREATE TABLE `video_submission_edits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `submission_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `edited_by` enum('user','admin') NOT NULL DEFAULT 'user',
  `editor_name` varchar(100) DEFAULT NULL,
  `old_youtube_id` varchar(20) NOT NULL,
  `old_start_seconds` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `new_youtube_id` varchar(20) NOT NULL,
  `new_start_seconds` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `track_state` varchar(20) NOT NULL DEFAULT 'published',
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `admin_actions_log`
--
ALTER TABLE `admin_actions_log`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `admin_audit_logs`
--
ALTER TABLE `admin_audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indices de la tabla `auth_identities`
--
ALTER TABLE `auth_identities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `provider_idx` (`provider`,`provider_user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `avatars`
--
ALTER TABLE `avatars`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_avatars_creator` (`creator`);

--
-- Indices de la tabla `card_manager_cards`
--
ALTER TABLE `card_manager_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_archived` (`is_archived`),
  ADD KEY `idx_last_used` (`last_used_at`),
  ADD KEY `idx_card_type` (`card_type`),
  ADD KEY `idx_cards_group` (`group_id`);

--
-- Indices de la tabla `card_manager_groups`
--
ALTER TABLE `card_manager_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_groups_sort` (`sort_order`);

--
-- Indices de la tabla `coins`
--
ALTER TABLE `coins`
  ADD PRIMARY KEY (`user_id`);

--
-- Indices de la tabla `coin_transactions`
--
ALTER TABLE `coin_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `collage_backgrounds`
--
ALTER TABLE `collage_backgrounds`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `configuracion_tienda`
--
ALTER TABLE `configuracion_tienda`
  ADD PRIMARY KEY (`clave`);

--
-- Indices de la tabla `cupones`
--
ALTER TABLE `cupones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`);

--
-- Indices de la tabla `cupones_reglas_aplicabilidad`
--
ALTER TABLE `cupones_reglas_aplicabilidad`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cupon_id` (`cupon_id`);

--
-- Indices de la tabla `cupones_usos_destinatario`
--
ALTER TABLE `cupones_usos_destinatario`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cupon_destinatario` (`cupon_id`,`destinatario_epic_id`);

--
-- Indices de la tabla `cupones_usos_tracking`
--
ALTER TABLE `cupones_usos_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cupon_id` (`cupon_id`),
  ADD KEY `ip_usuario` (`ip_usuario`),
  ADD KEY `whatsapp_usuario` (`whatsapp_usuario`),
  ADD KEY `idx_cupon_user` (`cupon_id`,`user_id`);

--
-- Indices de la tabla `daily_discounts`
--
ALTER TABLE `daily_discounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_active` (`user_id`,`status`),
  ADD KEY `idx_user_history` (`user_id`,`created_at`);

--
-- Indices de la tabla `daily_special_prizes_pool`
--
ALTER TABLE `daily_special_prizes_pool`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_prize_day` (`active_date`,`prize_type`);

--
-- Indices de la tabla `daily_spin_config`
--
ALTER TABLE `daily_spin_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_date` (`event_date`);

--
-- Indices de la tabla `daily_spin_filter_config`
--
ALTER TABLE `daily_spin_filter_config`
  ADD PRIMARY KEY (`filter_key`);

--
-- Indices de la tabla `descuentos_automaticos`
--
ALTER TABLE `descuentos_automaticos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_regla` (`id_regla`);

--
-- Indices de la tabla `descuentos_manuales`
--
ALTER TABLE `descuentos_manuales`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `descuentos_reglas_aplicabilidad`
--
ALTER TABLE `descuentos_reglas_aplicabilidad`
  ADD PRIMARY KEY (`id`),
  ADD KEY `descuento_id` (`descuento_id`);

--
-- Indices de la tabla `detalles_pedido`
--
ALTER TABLE `detalles_pedido`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pedido_id` (`pedido_id`);

--
-- Indices de la tabla `detalles_pedido_destinatarios`
--
ALTER TABLE `detalles_pedido_destinatarios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_detalle_pedido_id` (`detalle_pedido_id`);

--
-- Indices de la tabla `dl_api_field_map`
--
ALTER TABLE `dl_api_field_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_scope_source` (`scope`,`source_key`),
  ADD KEY `idx_activo` (`activo`);

--
-- Indices de la tabla `dl_custom_entries`
--
ALTER TABLE `dl_custom_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_offer` (`offer_id`),
  ADD KEY `idx_activo` (`activo`);

--
-- Indices de la tabla `dl_event_log`
--
ALTER TABLE `dl_event_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_severity` (`severity`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_type` (`event_type`);

--
-- Indices de la tabla `dl_shop_overrides`
--
ALTER TABLE `dl_shop_overrides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_offer_field` (`offer_id`,`field_key`),
  ADD KEY `idx_activo` (`activo`);

--
-- Indices de la tabla `dl_state`
--
ALTER TABLE `dl_state`
  ADD PRIMARY KEY (`k`);

--
-- Indices de la tabla `email_login_tokens`
--
ALTER TABLE `email_login_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`);

--
-- Indices de la tabla `excluded_ips`
--
ALTER TABLE `excluded_ips`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `game_card_visuals`
--
ALTER TABLE `game_card_visuals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `card_key` (`card_key`);

--
-- Indices de la tabla `ips_bloqueadas`
--
ALTER TABLE `ips_bloqueadas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ip_address` (`ip_address`);

--
-- Indices de la tabla `logs_pedidos`
--
ALTER TABLE `logs_pedidos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `log_regalos_completados`
--
ALTER TABLE `log_regalos_completados`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pedido_id_log` (`pedido_id`);

--
-- Indices de la tabla `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_notif` (`user_id`,`dedupe_key`);

--
-- Indices de la tabla `ofertas_reglas`
--
ALTER TABLE `ofertas_reglas`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `ofertas_tipos_articulos`
--
ALTER TABLE `ofertas_tipos_articulos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_tipo` (`nombre_tipo`);

--
-- Indices de la tabla `page_visits`
--
ALTER TABLE `page_visits`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `pedidos`
--
ALTER TABLE `pedidos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo_pedido` (`codigo_pedido`),
  ADD KEY `idx_email_cliente` (`email_cliente`),
  ADD KEY `idx_nickname_cliente` (`nickname_fortnite_cliente`),
  ADD KEY `fk_pedido_usuario` (`user_id`);

--
-- Indices de la tabla `pistas_musicales`
--
ALTER TABLE `pistas_musicales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_pista` (`nombre_pista`),
  ADD KEY `idx_cosmetic` (`cosmetic_id`);

--
-- Indices de la tabla `productos_estaticos`
--
ALTER TABLE `productos_estaticos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `product_clicks`
--
ALTER TABLE `product_clicks`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `raffles`
--
ALTER TABLE `raffles`
  ADD PRIMARY KEY (`raffle_id`);

--
-- Indices de la tabla `raffle_bans`
--
ALTER TABLE `raffle_bans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_ip` (`ip_snapshot`),
  ADD KEY `idx_fp` (`fingerprint_snapshot`),
  ADD KEY `idx_active` (`lifted_at`);

--
-- Indices de la tabla `raffle_early_bird_rewards`
--
ALTER TABLE `raffle_early_bird_rewards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_raffle_user` (`raffle_id`,`user_id`),
  ADD KEY `idx_raffle_status` (`raffle_id`,`status`);

--
-- Indices de la tabla `raffle_entry_signals`
--
ALTER TABLE `raffle_entry_signals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_raffle_user` (`raffle_id`,`user_id`),
  ADD KEY `idx_ip` (`raffle_id`,`ip_address`),
  ADD KEY `idx_fp` (`raffle_id`,`fingerprint`);

--
-- Indices de la tabla `raffle_invitations`
--
ALTER TABLE `raffle_invitations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_raffle_user` (`raffle_id`,`user_id`),
  ADD KEY `idx_raffle` (`raffle_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indices de la tabla `raffle_manual_purchases`
--
ALTER TABLE `raffle_manual_purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_date` (`user_id`,`purchase_date`);

--
-- Indices de la tabla `raffle_participants`
--
ALTER TABLE `raffle_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `raffle_id` (`raffle_id`,`user_id`),
  ADD KEY `fk_part_user` (`user_id`);

--
-- Indices de la tabla `raffle_snapshots`
--
ALTER TABLE `raffle_snapshots`
  ADD PRIMARY KEY (`raffle_id`);

--
-- Indices de la tabla `raffle_strikes`
--
ALTER TABLE `raffle_strikes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_raffle` (`raffle_id`);

--
-- Indices de la tabla `raffle_viewers`
--
ALTER TABLE `raffle_viewers`
  ADD PRIMARY KEY (`raffle_id`,`viewer_key`),
  ADD KEY `idx_last_seen` (`raffle_id`,`last_seen`);

--
-- Indices de la tabla `raffle_welcome_grants`
--
ALTER TABLE `raffle_welcome_grants`
  ADD PRIMARY KEY (`user_id`);

--
-- Indices de la tabla `raffle_welcome_tickets`
--
ALTER TABLE `raffle_welcome_tickets`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_raffle` (`raffle_id`);

--
-- Indices de la tabla `raffle_whitelist`
--
ALTER TABLE `raffle_whitelist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_raffle_user` (`raffle_id`,`user_id`),
  ADD KEY `idx_raffle` (`raffle_id`);

--
-- Indices de la tabla `raffle_winners`
--
ALTER TABLE `raffle_winners`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_win_raffle` (`raffle_id`),
  ADD KEY `fk_win_user` (`user_id`);

--
-- Indices de la tabla `registros_actividad`
--
ALTER TABLE `registros_actividad`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `secciones_fondos`
--
ALTER TABLE `secciones_fondos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_section_key` (`section_key`);

--
-- Indices de la tabla `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`key_name`);

--
-- Indices de la tabla `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indices de la tabla `tareas_attachments`
--
ALTER TABLE `tareas_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attach_mision` (`mision_id`);

--
-- Indices de la tabla `tareas_changes`
--
ALTER TABLE `tareas_changes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_changes_created` (`created_at`);

--
-- Indices de la tabla `tareas_checklist_items`
--
ALTER TABLE `tareas_checklist_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chk_mision` (`mision_id`);

--
-- Indices de la tabla `tareas_colaboradores`
--
ALTER TABLE `tareas_colaboradores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_col_archived` (`is_archived`);

--
-- Indices de la tabla `tareas_comments`
--
ALTER TABLE `tareas_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_comments_mision` (`mision_id`);

--
-- Indices de la tabla `tareas_ideas`
--
ALTER TABLE `tareas_ideas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ideas_status` (`status`),
  ADD KEY `idx_ideas_pinned` (`is_pinned`),
  ADD KEY `idx_ideas_sort` (`sort_order`);

--
-- Indices de la tabla `tareas_idea_tags`
--
ALTER TABLE `tareas_idea_tags`
  ADD PRIMARY KEY (`idea_id`,`tag_id`),
  ADD KEY `idx_idea_tags_tag` (`tag_id`);

--
-- Indices de la tabla `tareas_misiones`
--
ALTER TABLE `tareas_misiones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mis_status` (`status`),
  ADD KEY `idx_mis_priority` (`priority`),
  ADD KEY `idx_mis_colab` (`colaborador_id`),
  ADD KEY `idx_mis_due` (`due_date`),
  ADD KEY `idx_mis_sort` (`sort_order`);

--
-- Indices de la tabla `tareas_mision_tags`
--
ALTER TABLE `tareas_mision_tags`
  ADD PRIMARY KEY (`mision_id`,`tag_id`),
  ADD KEY `idx_mision_tags_tag` (`tag_id`);

--
-- Indices de la tabla `tareas_tags`
--
ALTER TABLE `tareas_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_tag_name` (`name`);

--
-- Indices de la tabla `tareas_templates`
--
ALTER TABLE `tareas_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `tareas_template_items`
--
ALTER TABLE `tareas_template_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tpl_items_tpl` (`template_id`);

--
-- Indices de la tabla `tarjetas_v2_cards`
--
ALTER TABLE `tarjetas_v2_cards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card_profile` (`profile_id`),
  ADD KEY `idx_card_order` (`sort_order`,`created_at`),
  ADD KEY `idx_card_cooldown` (`cooldown_until`);

--
-- Indices de la tabla `tarjetas_v2_emitters`
--
ALTER TABLE `tarjetas_v2_emitters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_emitter_name` (`name`),
  ADD KEY `idx_emitter_order` (`sort_order`,`name`);

--
-- Indices de la tabla `tarjetas_v2_preferences`
--
ALTER TABLE `tarjetas_v2_preferences`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `tarjetas_v2_profiles`
--
ALTER TABLE `tarjetas_v2_profiles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_profile_emitter` (`emitter_id`),
  ADD KEY `idx_profile_order` (`sort_order`,`holder`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `public_code` (`public_code`),
  ADD UNIQUE KEY `game_username_norm` (`game_username_norm`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `public_display_name_norm` (`public_display_name_norm`);

--
-- Indices de la tabla `users_tutoriales_vistos`
--
ALTER TABLE `users_tutoriales_vistos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_cat` (`user_id`,`categoria`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indices de la tabla `user_nicks`
--
ALTER TABLE `user_nicks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_platform_user` (`user_id`,`platform`);

--
-- Indices de la tabla `user_stats`
--
ALTER TABLE `user_stats`
  ADD PRIMARY KEY (`user_id`);

--
-- Indices de la tabla `user_tokens`
--
ALTER TABLE `user_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_tokens_selector` (`selector`),
  ADD KEY `idx_user_tokens_user_id` (`user_id`),
  ADD KEY `idx_user_tokens_expires_at` (`expires_at`);

--
-- Indices de la tabla `verification_requests`
--
ALTER TABLE `verification_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `videos_objetos`
--
ALTER TABLE `videos_objetos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cosmetic_style` (`cosmetic_id`,`style_key`);

--
-- Indices de la tabla `videos_tienda`
--
ALTER TABLE `videos_tienda`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_id_video_url` (`id`,`video_url`(255)),
  ADD KEY `idx_last_checked` (`last_checked`),
  ADD KEY `idx_video_url` (`video_url`(255));

--
-- Indices de la tabla `video_bans`
--
ALTER TABLE `video_bans`
  ADD PRIMARY KEY (`user_id`);

--
-- Indices de la tabla `video_reports`
--
ALTER TABLE `video_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_active_report` (`active_key`),
  ADD KEY `idx_reporter` (`reporter_id`,`status`);

--
-- Indices de la tabla `video_report_reasons`
--
ALTER TABLE `video_report_reasons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_code` (`code`);

--
-- Indices de la tabla `video_submissions`
--
ALTER TABLE `video_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_track` (`track_name`),
  ADD KEY `idx_cosmetic` (`cosmetic_id`),
  ADD KEY `idx_user_status` (`user_id`,`status`);

--
-- Indices de la tabla `video_submission_edits`
--
ALTER TABLE `video_submission_edits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_submission` (`submission_id`,`created_at`),
  ADD KEY `idx_user` (`user_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `admin_actions_log`
--
ALTER TABLE `admin_actions_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `admin_audit_logs`
--
ALTER TABLE `admin_audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `auth_identities`
--
ALTER TABLE `auth_identities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `avatars`
--
ALTER TABLE `avatars`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `card_manager_cards`
--
ALTER TABLE `card_manager_cards`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `card_manager_groups`
--
ALTER TABLE `card_manager_groups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `coin_transactions`
--
ALTER TABLE `coin_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cupones`
--
ALTER TABLE `cupones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cupones_reglas_aplicabilidad`
--
ALTER TABLE `cupones_reglas_aplicabilidad`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cupones_usos_destinatario`
--
ALTER TABLE `cupones_usos_destinatario`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cupones_usos_tracking`
--
ALTER TABLE `cupones_usos_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `daily_discounts`
--
ALTER TABLE `daily_discounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `daily_special_prizes_pool`
--
ALTER TABLE `daily_special_prizes_pool`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `daily_spin_config`
--
ALTER TABLE `daily_spin_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `descuentos_automaticos`
--
ALTER TABLE `descuentos_automaticos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `descuentos_manuales`
--
ALTER TABLE `descuentos_manuales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `descuentos_reglas_aplicabilidad`
--
ALTER TABLE `descuentos_reglas_aplicabilidad`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `detalles_pedido`
--
ALTER TABLE `detalles_pedido`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `detalles_pedido_destinatarios`
--
ALTER TABLE `detalles_pedido_destinatarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `dl_api_field_map`
--
ALTER TABLE `dl_api_field_map`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `dl_custom_entries`
--
ALTER TABLE `dl_custom_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `dl_event_log`
--
ALTER TABLE `dl_event_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `dl_shop_overrides`
--
ALTER TABLE `dl_shop_overrides`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `email_login_tokens`
--
ALTER TABLE `email_login_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `excluded_ips`
--
ALTER TABLE `excluded_ips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `game_card_visuals`
--
ALTER TABLE `game_card_visuals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ips_bloqueadas`
--
ALTER TABLE `ips_bloqueadas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `logs_pedidos`
--
ALTER TABLE `logs_pedidos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `log_regalos_completados`
--
ALTER TABLE `log_regalos_completados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ofertas_reglas`
--
ALTER TABLE `ofertas_reglas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ofertas_tipos_articulos`
--
ALTER TABLE `ofertas_tipos_articulos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `page_visits`
--
ALTER TABLE `page_visits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `pedidos`
--
ALTER TABLE `pedidos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `pistas_musicales`
--
ALTER TABLE `pistas_musicales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `productos_estaticos`
--
ALTER TABLE `productos_estaticos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `product_clicks`
--
ALTER TABLE `product_clicks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffles`
--
ALTER TABLE `raffles`
  MODIFY `raffle_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffle_bans`
--
ALTER TABLE `raffle_bans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffle_early_bird_rewards`
--
ALTER TABLE `raffle_early_bird_rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffle_entry_signals`
--
ALTER TABLE `raffle_entry_signals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffle_invitations`
--
ALTER TABLE `raffle_invitations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffle_manual_purchases`
--
ALTER TABLE `raffle_manual_purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffle_participants`
--
ALTER TABLE `raffle_participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffle_strikes`
--
ALTER TABLE `raffle_strikes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffle_whitelist`
--
ALTER TABLE `raffle_whitelist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `raffle_winners`
--
ALTER TABLE `raffle_winners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `registros_actividad`
--
ALTER TABLE `registros_actividad`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `secciones_fondos`
--
ALTER TABLE `secciones_fondos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_attachments`
--
ALTER TABLE `tareas_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_changes`
--
ALTER TABLE `tareas_changes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_checklist_items`
--
ALTER TABLE `tareas_checklist_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_colaboradores`
--
ALTER TABLE `tareas_colaboradores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_comments`
--
ALTER TABLE `tareas_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_ideas`
--
ALTER TABLE `tareas_ideas`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_misiones`
--
ALTER TABLE `tareas_misiones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_tags`
--
ALTER TABLE `tareas_tags`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_templates`
--
ALTER TABLE `tareas_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tareas_template_items`
--
ALTER TABLE `tareas_template_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tarjetas_v2_cards`
--
ALTER TABLE `tarjetas_v2_cards`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tarjetas_v2_emitters`
--
ALTER TABLE `tarjetas_v2_emitters`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tarjetas_v2_profiles`
--
ALTER TABLE `tarjetas_v2_profiles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `users_tutoriales_vistos`
--
ALTER TABLE `users_tutoriales_vistos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `user_nicks`
--
ALTER TABLE `user_nicks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `user_tokens`
--
ALTER TABLE `user_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `verification_requests`
--
ALTER TABLE `verification_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `videos_objetos`
--
ALTER TABLE `videos_objetos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `video_reports`
--
ALTER TABLE `video_reports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `video_report_reasons`
--
ALTER TABLE `video_report_reasons`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `video_submissions`
--
ALTER TABLE `video_submissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `video_submission_edits`
--
ALTER TABLE `video_submission_edits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `auth_identities`
--
ALTER TABLE `auth_identities`
  ADD CONSTRAINT `fk_auth_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `coins`
--
ALTER TABLE `coins`
  ADD CONSTRAINT `fk_coins_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `coin_transactions`
--
ALTER TABLE `coin_transactions`
  ADD CONSTRAINT `fk_trans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `detalles_pedido_destinatarios`
--
ALTER TABLE `detalles_pedido_destinatarios`
  ADD CONSTRAINT `fk_destinatario_detalle` FOREIGN KEY (`detalle_pedido_id`) REFERENCES `detalles_pedido` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `log_regalos_completados`
--
ALTER TABLE `log_regalos_completados`
  ADD CONSTRAINT `fk_log_regalos_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `pedidos`
--
ALTER TABLE `pedidos`
  ADD CONSTRAINT `fk_pedido_usuario` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `raffle_participants`
--
ALTER TABLE `raffle_participants`
  ADD CONSTRAINT `fk_part_raffle` FOREIGN KEY (`raffle_id`) REFERENCES `raffles` (`raffle_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_part_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `raffle_winners`
--
ALTER TABLE `raffle_winners`
  ADD CONSTRAINT `fk_win_raffle` FOREIGN KEY (`raffle_id`) REFERENCES `raffles` (`raffle_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_win_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `tareas_attachments`
--
ALTER TABLE `tareas_attachments`
  ADD CONSTRAINT `fk_attach_mision` FOREIGN KEY (`mision_id`) REFERENCES `tareas_misiones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `tareas_checklist_items`
--
ALTER TABLE `tareas_checklist_items`
  ADD CONSTRAINT `fk_chk_mision` FOREIGN KEY (`mision_id`) REFERENCES `tareas_misiones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `tareas_comments`
--
ALTER TABLE `tareas_comments`
  ADD CONSTRAINT `fk_comments_mision` FOREIGN KEY (`mision_id`) REFERENCES `tareas_misiones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `tareas_idea_tags`
--
ALTER TABLE `tareas_idea_tags`
  ADD CONSTRAINT `fk_idea_tags_idea` FOREIGN KEY (`idea_id`) REFERENCES `tareas_ideas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_idea_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tareas_tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `tareas_misiones`
--
ALTER TABLE `tareas_misiones`
  ADD CONSTRAINT `fk_mis_colaborador` FOREIGN KEY (`colaborador_id`) REFERENCES `tareas_colaboradores` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `tareas_mision_tags`
--
ALTER TABLE `tareas_mision_tags`
  ADD CONSTRAINT `fk_mision_tags_mision` FOREIGN KEY (`mision_id`) REFERENCES `tareas_misiones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mision_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tareas_tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `tareas_template_items`
--
ALTER TABLE `tareas_template_items`
  ADD CONSTRAINT `fk_tpl_items_tpl` FOREIGN KEY (`template_id`) REFERENCES `tareas_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `tarjetas_v2_cards`
--
ALTER TABLE `tarjetas_v2_cards`
  ADD CONSTRAINT `fk_card_profile` FOREIGN KEY (`profile_id`) REFERENCES `tarjetas_v2_profiles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `tarjetas_v2_profiles`
--
ALTER TABLE `tarjetas_v2_profiles`
  ADD CONSTRAINT `fk_profile_emitter` FOREIGN KEY (`emitter_id`) REFERENCES `tarjetas_v2_emitters` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `user_nicks`
--
ALTER TABLE `user_nicks`
  ADD CONSTRAINT `fk_nicks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `user_stats`
--
ALTER TABLE `user_stats`
  ADD CONSTRAINT `fk_stats_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
