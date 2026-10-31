/** .env, baked in at build time (see .env.example). */
interface ImportMetaEnv {
  readonly VITE_API_MODE?: string;
  readonly VITE_AUTH_URL?: string;
  readonly VITE_SITE_URL?: string;
  readonly VITE_LICENCE_PUBLIC_KEY?: string;
  readonly VITE_CLIENT_ID?: string;
  readonly VITE_OAUTH_PORT?: string;
  readonly VITE_WORKSPACE_URL?: string;
  readonly VITE_PRODUCT_PATH?: string;
  readonly VITE_RETAIL_CLIENT_ID?: string;
  readonly VITE_RETAIL_PATH?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
