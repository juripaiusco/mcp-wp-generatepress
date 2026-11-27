# MCP WP GeneratePress

Plugin WordPress che espone **GeneratePress**, **GP Premium**, i contenuti del sito e un sistema di **snapshot** come *abilities* dell'[Abilities API](https://developer.wordpress.org/apis/abilities/) di WordPress. Il plugin [MCP Adapter](https://github.com/WordPress/mcp-adapter) le rende disponibili ad agenti AI (Claude Code, Claude Desktop, Cursor, …) tramite il Model Context Protocol.

In pratica: un agente può leggere e modificare palette colori, tipografia, layout, logo, CSS aggiuntivo, Elements e contenuti del sito. Ogni modifica al design viene preceduta da uno snapshot automatico, quindi si può sempre tornare indietro.

---

## Indice

- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Collegare un client MCP](#collegare-un-client-mcp)
- [Abilities disponibili](#abilities-disponibili)
- [Gruppi di impostazioni](#gruppi-di-impostazioni)
- [Snapshot e rollback](#snapshot-e-rollback)
- [Esempi](#esempi)
- [Sicurezza](#sicurezza)
- [Uso via REST API](#uso-via-rest-api)
- [Sviluppo](#sviluppo)
- [Struttura del codice](#struttura-del-codice)
- [Roadmap](#roadmap)
- [Licenza](#licenza)

---

## Requisiti

| Componente | Versione | Note |
|---|---|---|
| WordPress | 6.9+ | Abilities API nel core. Testato su 7.1.5 |
| PHP | 7.4+ | Testato su 8.x |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | 0.7+ | Espone le abilities via MCP. Testato su 0.7.2 |
| GeneratePress | 3.x | Opzionale: senza il tema le abilities di design non vengono registrate. Testato su 3.6.1 |
| GP Premium | 2.x | Opzionale: senza il plugin le abilities premium non vengono registrate. Testato su 2.5.6 |

Le abilities di **contenuti**, **snapshot** e **panoramica** funzionano su qualsiasi tema.

## Installazione

1. Scarica lo zip della repo (oppure `git clone`) e rinomina la cartella in `mcp-wp-generatepress`.
2. Caricala in `wp-content/plugins/` (o da *Bacheca → Plugin → Aggiungi nuovo → Carica plugin*).
3. Attiva **MCP Adapter** e **MCP WP GeneratePress**.
4. Crea una **Application Password** per un utente amministratore: *Utenti → Profilo → Password applicazioni*.

> In locale su `http://` le Application Password funzionano solo se in `wp-config.php` c'è `define( 'WP_ENVIRONMENT_TYPE', 'local' );`.

## Collegare un client MCP

L'endpoint del server MCP predefinito è:

```
https://TUO-SITO/wp-json/mcp/mcp-adapter-default-server
```

### Claude Code

```bash
claude mcp add wordpress-mio-sito --scope local \
  -e WP_API_URL="https://TUO-SITO/wp-json/mcp/mcp-adapter-default-server" \
  -e WP_API_USERNAME="utente-admin" \
  -e WP_API_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
  -- npx -y @automattic/mcp-wordpress-remote@latest
```

Con `--scope local` le credenziali restano in `~/.claude.json` e non finiscono nei file del progetto. Dopo l'aggiunta riavvia la sessione.

### Claude Desktop / altri client

```json
{
  "mcpServers": {
    "wordpress-mio-sito": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://TUO-SITO/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "utente-admin",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

### Come le vede l'agente

MCP Adapter espone tre tool generici:

| Tool MCP | Uso |
|---|---|
| `mcp-adapter-discover-abilities` | Elenca tutte le abilities disponibili |
| `mcp-adapter-get-ability-info` | Schema di input di un'ability |
| `mcp-adapter-execute-ability` | Esegue un'ability: `{ "ability_name": "gpmcp/...", "parameters": { ... } }` |

Conviene partire sempre da `gpmcp/get-overview`.

## Abilities disponibili

Tutte le abilities usano il namespace `gpmcp/`.

### Panoramica

| Ability | Descrizione | Permesso |
|---|---|---|
| `get-overview` | Tema attivo, versioni GP/GP Premium, tipografia dinamica, gruppi di impostazioni, moduli premium, logo, numero di snapshot | `edit_theme_options` |

### Design GeneratePress

*Registrate solo se GeneratePress è il tema attivo (o il tema padre).*

| Ability | Descrizione | Snapshot auto |
|---|---|:---:|
| `get-settings` | Legge un [gruppo di impostazioni](#gruppi-di-impostazioni): `stored`, e con `include_defaults` anche `defaults`/`effective`. Filtro `keys` | — |
| `update-settings` | Merge parziale di `values` nel gruppo, `remove_keys` per tornare al default. Le chiavi sono validate contro i default e i colori validati come CSS | ✅ |
| `get-global-colors` | Palette globale (`contrast`, `contrast-2`, `contrast-3`, `base`, `base-2`, `base-3`, `accent`, più quelle custom) | — |
| `set-global-colors` | `mode: merge` (per slug) o `replace`. Gli slug diventano variabili `var(--slug)` | ✅ |
| `get-typography` | `font_manager`, regole `typography`, selettori e campi disponibili | — |
| `set-typography` | Registra font (anche Google Fonts) e regole per selettore (`body`, `all-headings`, `h1`…`h6`, `buttons`, `primary-menu-items`, `custom` + `customSelector`, …) | ✅ |
| `get-custom-css` | CSS aggiuntivo del Customizer | — |
| `set-custom-css` | `mode: append` (default), `prepend` o `replace`. I tag HTML vengono rifiutati | ✅ |
| `set-logo` | Logo da `media_id` o `url` (con download nella libreria media), più `logo_width` e `retina_logo_url` opzionali | ✅ |
| `clear-css-cache` | Forza la rigenerazione del CSS dinamico GeneratePress | — |

Permesso richiesto: `edit_theme_options`.

### GP Premium

*Registrate solo se GP Premium è attivo. Le abilities degli Elements richiedono anche il modulo Elements attivo.*

| Ability | Descrizione | Permesso |
|---|---|---|
| `list-modules` | Moduli con stato attivo/disattivo/deprecato | `edit_theme_options` |
| `toggle-module` | Attiva o disattiva un modulo (`key`, `active`). Crea uno snapshot automatico | `manage_options` |
| `list-elements` | Elements, filtrabili per `type` (`hook`, `block`, `layout`, `header`) e `status` | `edit_theme_options` |
| `get-element` | Element completo: contenuto e tutti i meta `_generate_*` | `edit_theme_options` |
| `upsert-element` | Crea (senza `id`) o aggiorna (con `id`) un Element: tipo, hook, priorità, `block_type`, condizioni di visualizzazione, esclusione e utente, meta extra. **Default `draft`** | `edit_theme_options` |

Le condizioni hanno il formato `[{ "rule": "general:site", "object": "" }]`. Regole comuni: `general:site`, `general:front_page`, `general:blog`, `general:singular`, `general:archive`, `post:post`, `post:page`.

### Contenuti

| Ability | Descrizione | Permesso |
|---|---|---|
| `list-posts` | Elenca i contenuti per `post_type`, `status`, `search`, `category`, con paginazione | `edit_posts` |
| `get-post` | Contenuto completo, termini, immagine in evidenza, template | `edit_post` |
| `create-post` | Crea un post o una pagina. **Default `draft`**. Categorie per ID, tag per nome | `create_posts` (+ `publish_posts` per pubblicare) |
| `update-post` | Aggiorna solo i campi passati | `edit_post` (+ `publish_posts`) |
| `list-terms` | Termini di una tassonomia pubblica | `edit_posts` |
| `create-term` | Crea un termine | `manage_terms` della tassonomia |
| `upload-media` | Scarica un'immagine da URL nella libreria media, con `alt` e allegato opzionali | `upload_files` |

### Snapshot

| Ability | Descrizione |
|---|---|
| `snapshot-create` | Salva lo stato attuale con un `label` |
| `snapshot-list` | Elenca gli snapshot salvati |
| `snapshot-restore` | Ripristina uno snapshot per `id`. Prima salva automaticamente lo stato corrente |
| `snapshot-delete` | Elimina uno snapshot |

Permesso richiesto: `edit_theme_options`.

## Gruppi di impostazioni

Il parametro `group` di `get-settings` e `update-settings` indica quale opzione si sta leggendo o modificando:

| `group` | Opzione WP | Contenuto |
|---|---|---|
| `main` *(default)* | `generate_settings` | Layout, larghezze, colori di header/navigazione/contenuto/footer/form, tipografia, logo |
| `spacing` | `generate_spacing_settings` | Padding, margini, larghezza sidebar |
| `blog` | `generate_blog_settings` | Archivi, colonne, masonry, meta (GP Premium) |
| `menu_plus` | `generate_menu_plus_settings` | Sticky nav, off-canvas, mobile header (GP Premium) |
| `secondary_nav` | `generate_secondary_nav_settings` | Navigazione secondaria (GP Premium) |
| `backgrounds` | `generate_background_settings` | Immagini di sfondo (GP Premium) |
| `woocommerce` | `generate_woocommerce_settings` | WooCommerce (GP Premium) |

Per conoscere le chiavi valide di un gruppo usa `get-settings` con `include_defaults: true`. Una chiave che non compare tra i default viene rifiutata, a meno di passare `allow_unknown: true`.

Chiavi utili del gruppo `main`: `container_width`, `layout_setting`, `blog_layout_setting`, `single_layout_setting`, `content_layout_setting`, `background_color`, `text_color`, `link_color`, `link_color_hover`, `header_background_color`, `navigation_background_color`, `navigation_text_color`, `navigation_text_hover_color`, `navigation_text_current_color`, `form_button_background_color`, `form_button_background_color_hover`, `footer_background_color`, `footer_widget_background_color`.

Come valore dei colori puoi usare le variabili della palette, ad esempio `var(--accent)`: così le modifiche alla palette si propagano a tutto il sito.

## Snapshot e rollback

Uno snapshot salva:

- `generate_settings` e tutte le opzioni `generate_*_settings` (spacing, blog, menu plus, secondary nav, backgrounds, woocommerce, page header, hooks);
- lo stato dei moduli GP Premium (`generate_package_*`);
- i `theme_mods` del tema attivo (logo, menu, ID del CSS aggiuntivo, …);
- il CSS aggiuntivo del Customizer.

Funzionamento:

- `update-settings`, `set-global-colors`, `set-typography`, `set-custom-css`, `set-logo` e `toggle-module` creano uno snapshot automatico (`auto: …`) **prima** di scrivere, e ne restituiscono l'`id`.
- `snapshot-restore` salva prima lo stato attuale (`auto: prima del ripristino di …`), così anche un ripristino si può annullare.
- Un'opzione che non esisteva al momento dello snapshot viene eliminata al ripristino. Lo stato torna identico all'originale (verificato con un confronto delle opzioni nel DB prima/dopo).
- Si conservano al massimo gli ultimi **30** snapshot, nell'opzione `gpmcp_snapshots` (non autoload).
- Uno snapshot si può ripristinare solo sullo stesso tema per cui è stato creato.

> Gli snapshot **non** includono Elements né contenuti: post e pagine hanno già le revisioni di WordPress.

## Esempi

Parametri da passare a `mcp-adapter-execute-ability`.

**Palette di brand**
```json
{
  "ability_name": "gpmcp/set-global-colors",
  "parameters": {
    "colors": [
      { "slug": "accent", "color": "#64a432" },
      { "slug": "brand", "name": "Brand", "color": "#496354" },
      { "slug": "base-2", "color": "#F7F4EA" }
    ]
  }
}
```

**Link e pulsanti sui colori della palette**
```json
{
  "ability_name": "gpmcp/update-settings",
  "parameters": {
    "group": "main",
    "values": {
      "link_color": "var(--brand)",
      "link_color_hover": "var(--accent)",
      "form_button_background_color": "var(--accent)",
      "form_button_background_color_hover": "var(--brand)"
    }
  }
}
```

**Google Font per testo e titoli**
```json
{
  "ability_name": "gpmcp/set-typography",
  "parameters": {
    "fonts": [
      { "fontFamily": "Montserrat", "googleFont": true, "googleFontCategory": "sans-serif", "googleFontVariants": "400, 700" }
    ],
    "typography": [
      { "selector": "body", "fontFamily": "Montserrat", "fontSize": "17", "lineHeight": "1.6" },
      { "selector": "all-headings", "fontFamily": "Montserrat", "fontWeight": "700" }
    ]
  }
}
```

**Hook Element (banner sopra l'header, salvato in bozza)**
```json
{
  "ability_name": "gpmcp/upsert-element",
  "parameters": {
    "title": "Banner promo",
    "type": "hook",
    "hook": "generate_before_header",
    "content": "<div class=\"promo\">Spedizione gratuita sopra 49€</div>",
    "display_conditions": [{ "rule": "general:site" }]
  }
}
```

**Annullare una modifica**
```json
{ "ability_name": "gpmcp/snapshot-restore", "parameters": { "id": "20261127-105327-YW4n" } }
```

## Sicurezza

- Ogni ability controlla le capability di WordPress dell'utente collegato (vedi le tabelle). Usa un utente dedicato con il ruolo minimo che ti serve.
- **Bozza di default**: post, pagine ed Elements nuovi vengono creati in `draft`. Per pubblicare serve `status: "publish"` esplicito e la capability `publish_posts`.
- **Esecuzione PHP bloccata**: il meta `_generate_hook_execute_php` degli Elements non si può scrivere via MCP, perché equivarrebbe a eseguire codice remoto. Resta modificabile solo dall'interfaccia di WordPress.
- I contenuti HTML passano da `wp_kses_post` se l'utente non ha `unfiltered_html`.
- Il CSS aggiuntivo rifiuta i tag HTML. I colori sono validati: hex, `rgb()`/`rgba()`, `hsl()`/`hsla()`, `var(--x)`, `transparent`, `currentColor`.
- Le chiavi delle impostazioni sono validate contro i default di GeneratePress.
- Non salvare mai le Application Password nel repository. Revocale da *Profilo* quando non servono più.

## Uso via REST API

Le abilities sono esposte anche dalla REST API del core (`show_in_rest`), utile per test e script:

```
GET|POST|DELETE /wp-json/wp-abilities/v1/abilities/gpmcp/<nome>/run
```

Il metodo HTTP dipende dalle annotazioni dell'ability:

| Tipo | Metodo | Input |
|---|---|---|
| `readonly` (get-*, list-*) | `GET` | query string, es. `?input[group]=main&input[keys][]=link_color` |
| destructive (`snapshot-restore`, `snapshot-delete`) | `DELETE` | query string |
| altre | `POST` | body JSON `{ "input": { … } }` |

```bash
curl -u 'utente:app password' \
  "https://TUO-SITO/wp-json/wp-abilities/v1/abilities/gpmcp/get-global-colors/run"
```

Gli errori restituiscono `400` (input non valido), `403` (permesso mancante o meta vietato) e `404` (risorsa non trovata).

## Sviluppo

Flusso consigliato con un WordPress locale in Docker:

```yaml
# docker-compose.yml: monta la repo direttamente come plugin
services:
  php:
    volumes:
      - ./html:/var/www/html/
      - ../mcp-wp-generatepress:/var/www/html/wp-content/plugins/mcp-wp-generatepress
```

```php
// wp-config.php (solo locale)
define( 'WP_ENVIRONMENT_TYPE', 'local' );          // Application Password su http
define( 'WP_HOME', 'http://localhost:81' );
define( 'WP_SITEURL', 'http://localhost:81' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Controlli rapidi:

```bash
# lint
for f in $(find . -name '*.php'); do php -l "$f"; done

# abilities registrate
curl -s -u "$WPAUTH" http://localhost:81/wp-json/wp-abilities/v1/abilities | jq '.[].name'
```

### Aggiungere un'ability

1. Scegli il file in `includes/abilities/` della categoria giusta (o creane uno nuovo e caricalo in `GPMCP_Plugin::register_abilities()`).
2. Registrala con l'helper `gpmcp_register()`, che aggiunge il namespace `gpmcp/`, `meta.mcp.public = true` e un `permission_callback` basato sulla capability:

```php
gpmcp_register(
	'nome-ability',
	array(
		'label'            => 'Etichetta',
		'description'      => 'Cosa fa (letta dall\'agente: sii preciso).',
		'category'         => 'gpmcp-design',
		'capability'       => 'edit_theme_options', // default
		'readonly'         => false,                // annotazioni MCP
		'destructive'      => false,
		'idempotent'       => true,
		'input_schema'     => gpmcp_object_schema(
			array( 'campo' => array( 'type' => 'string' ) ),
			array( 'campo' ) // obbligatori
		),
		'execute_callback' => static function ( $input ) {
			// ...
			return array( 'ok' => true ); // oppure gpmcp_error( 'gpmcp_xxx', 'Messaggio' )
		},
	)
);
```

3. Per le modifiche al design chiama `gpmcp_snapshot_create( 'auto: …' )` prima di scrivere e `gpmcp_clear_css_cache()` dopo.

## Struttura del codice

```
mcp-wp-generatepress/
├── mcp-wp-generatepress.php        # header plugin e bootstrap
└── includes/
    ├── class-plugin.php            # categorie + registrazione condizionale (GP / GP Premium)
    ├── helpers.php                 # gpmcp_register, schema, sanitize, colori, cache CSS, gruppi settings
    └── abilities/
        ├── overview.php            # get-overview
        ├── design.php              # settings, colori, tipografia, CSS, logo
        ├── premium.php             # moduli ed Elements GP Premium
        ├── content.php             # post, pagine, termini, media
        └── snapshot.php            # snapshot / restore
```

Categorie delle abilities: `gpmcp-overview`, `gpmcp-design`, `gpmcp-premium`, `gpmcp-content`, `gpmcp-snapshot`.

## Roadmap

- [ ] Snapshot degli Elements
- [ ] Gestione menu di navigazione
- [ ] Widget e aree footer
- [ ] Plugin gemello per Elementor (`mcp-wp-elementor`)

## Licenza

[MIT](LICENSE) © Juri Paiusco
