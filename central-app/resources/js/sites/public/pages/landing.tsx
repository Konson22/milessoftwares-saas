import ProductProfile from '@/sites/public/components/product-profile';
import type { ProductProfileProps } from '@/sites/public/components/product-profile';

/**
 * Every ERP and application page without one of its own (Pharmacy and
 * Finance have theirs): the product profile, from the catalog and the
 * registry. A product not released yet offers its waitlist.
 */
export default function LandingPage(props: ProductProfileProps) {
    return <ProductProfile {...props} />;
}
