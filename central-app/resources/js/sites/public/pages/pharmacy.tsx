import ProductProfile from '@/sites/public/components/product-profile';
import type { ProductProfileProps } from '@/sites/public/components/product-profile';

/** Pharmacy ERP: the product profile, set up for a pharmacy. */
export default function PharmacyPage(props: ProductProfileProps) {
    return <ProductProfile {...props} customer="pharmacy" />;
}
