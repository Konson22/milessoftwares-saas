import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/field';
import { store, update } from '@/routes/products';

type Product = { id: string; name: string; code: string; active: boolean };

export default function Products({
    products,
    canManage,
}: {
    products: Product[];
    canManage: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Fuel grades" />
            <PageHeader
                title="Fuel grades"
                description="Grades are shared by every station. Prices are set per station."
            />
            <Panel>
                <PanelHeader title="Grades" />
                <PanelBody className="grid gap-4">
                    <ul className="text-sm">
                        {products.map((product) => (
                            <li key={product.id}>
                                {product.code} — {product.name}
                                {product.active ? '' : ' (inactive)'}
                            </li>
                        ))}
                    </ul>
                    {canManage && (
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <Field id="name" label="Name" error={errors.name}>
                                <Input id="name" name="name" required />
                            </Field>
                            <Field id="code" label="Code" error={errors.code}>
                                <Input id="code" name="code" required />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Add grade</Button>
                            </div>
                        </Form>
                    )}
                    {canManage &&
                        products.map((product) => (
                            <Form
                                key={product.id}
                                {...update.form(product.id)}
                                className="flex flex-wrap items-end gap-2"
                            >
                                <Input
                                    name="name"
                                    defaultValue={product.name}
                                    aria-label="Name"
                                    required
                                />
                                <Input
                                    name="code"
                                    defaultValue={product.code}
                                    aria-label="Code"
                                    required
                                />
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="hidden"
                                        name="active"
                                        value="0"
                                    />
                                    <input
                                        type="checkbox"
                                        name="active"
                                        value="1"
                                        defaultChecked={product.active}
                                    />
                                    Active
                                </label>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                >
                                    Save
                                </Button>
                            </Form>
                        ))}
                </PanelBody>
            </Panel>
        </>
    );
}
