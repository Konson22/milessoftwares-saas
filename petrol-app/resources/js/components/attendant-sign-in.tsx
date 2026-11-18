import { Copy, Eye, EyeOff, Wand2 } from "lucide-react";
import { useState } from "react";
import { Button } from "@miles/ui/components/ui/button";
import { Input } from "@miles/ui/components/ui/input";
import { cn } from "@miles/ui/lib/utils";
import { Field, Select } from "@/components/field";

/**
 * Giving a pump attendant a sign-in for the mobile app: the choice on the
 * attendant forms (Attendants and Staff), and the hand-over shown once after.
 */

export type UserOption = { id: string; name: string; email: string };

type SignInMode = "none" | "create" | "link";

/** Twelve characters without look-alikes (0/O, 1/l/I): read out at the pump. */
function generatePassword(): string {
    const alphabet = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    const values = crypto.getRandomValues(new Uint32Array(12));
    const chars = Array.from(
        values,
        (value) => alphabet[value % alphabet.length],
    );

    return `${chars.slice(0, 4).join("")}-${chars.slice(4, 8).join("")}-${chars.slice(8).join("")}`;
}

/**
 * How the attendant signs in: not at all, a new username account with a
 * temporary password, or an existing account.
 */
export function SignInChoice({
    idPrefix,
    users,
    errors,
    allowLink,
}: {
    idPrefix: string;
    users: UserOption[];
    errors: Record<string, string>;
    allowLink: boolean;
}) {
    const [mode, setMode] = useState<SignInMode>("none");
    const [password, setPassword] = useState("");
    const [visible, setVisible] = useState(false);

    const options: [SignInMode, string][] = [
        ["none", "No sign-in"],
        ["create", "Create a sign-in"],
        ...(allowLink && users.length > 0
            ? ([["link", "Use an existing account"]] as [SignInMode, string][])
            : []),
    ];

    return (
        <fieldset className="grid gap-3 sm:col-span-full">
            <legend className="mb-1.5 text-sm font-medium">
                Sign-in for the mobile app
            </legend>
            <div className="flex flex-wrap gap-2">
                {options.map(([value, label]) => (
                    <label
                        key={value}
                        className={cn(
                            "flex cursor-pointer items-center gap-2 border px-3 py-1.5 text-sm",
                            mode === value && "border-primary bg-primary/5",
                        )}
                    >
                        <input
                            type="radio"
                            name={`${idPrefix}-sign-in-mode`}
                            checked={mode === value}
                            onChange={() => setMode(value)}
                            className="accent-primary"
                        />
                        {label}
                    </label>
                ))}
            </div>
            <input
                type="hidden"
                name="create_sign_in"
                value={mode === "create" ? "1" : "0"}
            />

            {mode === "create" && (
                <div className="grid gap-3 border-l-2 border-primary/30 pl-3 sm:grid-cols-2">
                    <Field
                        id={`${idPrefix}-username`}
                        label="Username"
                        error={errors.username}
                        hint="What they type to sign in. Letters, digits, dots, dashes."
                    >
                        <Input
                            id={`${idPrefix}-username`}
                            name="username"
                            autoCapitalize="none"
                            autoComplete="off"
                            spellCheck={false}
                            maxLength={32}
                            placeholder="e.g. amina.lual"
                            required
                        />
                    </Field>
                    <Field
                        id={`${idPrefix}-password`}
                        label="Temporary password"
                        error={errors.password}
                        hint="At least 8 characters. They choose their own the first time they sign in."
                    >
                        <div className="flex gap-1">
                            <Input
                                id={`${idPrefix}-password`}
                                name="password"
                                type={visible ? "text" : "password"}
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                autoComplete="new-password"
                                minLength={8}
                                maxLength={128}
                                required
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={() => setVisible(!visible)}
                                aria-label={
                                    visible ? "Hide password" : "Show password"
                                }
                            >
                                {visible ? (
                                    <EyeOff aria-hidden />
                                ) : (
                                    <Eye aria-hidden />
                                )}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={() => {
                                    setPassword(generatePassword());
                                    setVisible(true);
                                }}
                                aria-label="Suggest a password"
                                title="Suggest a password"
                            >
                                <Wand2 aria-hidden />
                            </Button>
                        </div>
                    </Field>
                    {errors.sign_in && (
                        <p className="text-sm text-destructive sm:col-span-2">
                            {errors.sign_in}
                        </p>
                    )}
                </div>
            )}

            {mode === "link" && (
                <div className="border-l-2 border-primary/30 pl-3 sm:max-w-sm">
                    <Field
                        id={`${idPrefix}-user`}
                        label="Account"
                        error={errors.user_id}
                    >
                        <Select
                            id={`${idPrefix}-user`}
                            name="user_id"
                            placeholder="Choose…"
                            options={users.map((user) => ({
                                value: user.id,
                                label: `${user.name} (${user.email})`,
                            }))}
                            required
                        />
                    </Field>
                </div>
            )}
        </fieldset>
    );
}

/**
 * What to hand the attendant, once. The password is the one the manager
 * typed: it is not shown again.
 */
export function HandOver({
    name,
    username,
    expiresAt,
    signInUrl,
    roleNote,
}: {
    name: string;
    username: string;
    expiresAt: string;
    signInUrl: string;
    roleNote: string | null;
}) {
    const [copied, setCopied] = useState(false);
    const text = `Sign in to the Petrol app (or ${signInUrl})\nUsername: ${username}\nPassword: the temporary one you were given\nYou choose your own the first time. It works until ${expiresAt}.`;

    return (
        <section
            aria-labelledby="hand-over-title"
            className="grid gap-2 border border-emerald-300 bg-emerald-50 p-3 text-sm dark:border-emerald-500/40 dark:bg-emerald-500/10"
        >
            <h2 id="hand-over-title" className="font-semibold">
                Sign-in ready for {name}
            </h2>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                <dt className="text-muted-foreground">Username</dt>
                <dd className="font-mono font-semibold">{username}</dd>
                <dt className="text-muted-foreground">Password</dt>
                <dd>The temporary password you just typed</dd>
                <dt className="text-muted-foreground">Works until</dt>
                <dd>{expiresAt}</dd>
            </dl>
            <p className="text-muted-foreground">
                They sign in with these in the Petrol mobile app and choose
                their own password straight away.
            </p>
            {roleNote && (
                <p className="text-amber-800 dark:text-amber-300">{roleNote}</p>
            )}
            <div>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        navigator.clipboard
                            .writeText(text)
                            .then(() => setCopied(true))
                            .catch(() => setCopied(false))
                    }
                >
                    <Copy aria-hidden />
                    {copied ? "Copied" : "Copy instructions"}
                </Button>
            </div>
        </section>
    );
}
