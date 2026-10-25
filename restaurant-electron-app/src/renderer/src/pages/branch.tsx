import { Building2 } from 'lucide-react';

import { Button, Notice } from '@/components/ui';
import { useAuth } from '@/store/auth';

export function BranchPage() {
  const me = useAuth((s) => s.me);
  const chooseBranch = useAuth((s) => s.chooseBranch);
  const signOut = useAuth((s) => s.signOut);
  const branches = me?.branches ?? [];

  return (
    <div className="flex h-full items-center justify-center p-8">
      <div className="w-full max-w-md space-y-5">
        <div>
          <h1 className="text-2xl font-semibold">Where are you working?</h1>
          <p className="text-sm text-muted-foreground">
            {me?.organization.name} · {me?.user.name}
          </p>
        </div>

        {branches.length === 0 ? (
          <Notice>
            {me?.reason === 'no_role'
              ? 'You have no restaurant role in any branch yet. Ask the owner to give you one.'
              : 'This restaurant has no branches yet. The owner adds them on the web.'}
          </Notice>
        ) : (
          <div className="divide-y divide-border border border-border bg-card">
            {branches.map((branch) => (
              <button
                key={branch.id}
                type="button"
                onClick={() => void chooseBranch(branch.id)}
                className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-accent"
              >
                <Building2 size={18} className="text-muted-foreground" />
                <span className="flex-1">
                  <span className="block font-medium">{branch.name}</span>
                  <span className="block text-xs text-muted-foreground">
                    {branch.code}
                    {branch.city ? ` · ${branch.city}` : ''}
                  </span>
                </span>
              </button>
            ))}
          </div>
        )}

        <Button variant="ghost" onClick={() => void signOut()}>
          Sign out
        </Button>
      </div>
    </div>
  );
}
