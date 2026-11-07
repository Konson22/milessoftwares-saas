/**
 * Pages of a business on its own host ({business}.<root>). Every workspace
 * page is served on its tenant host, so these are relative paths: the
 * generated route helpers would need the tenant's address to build them.
 */
export const workspaceRoutes = {
    home: '/',
    explore: '/manage/explore',
    trial: (app?: string) =>
        `/manage/trial${app ? `?app=${encodeURIComponent(app)}` : ''}`,
    trialStore: '/manage/trial',
    settings: '/manage/settings',
    details: '/manage/details',
    billingDetails: '/manage/billing-details',
    slug: '/manage/slug',
    subscription: '/manage/subscription',
    plans: '/manage/plans',
    planRequests: '/manage/plan-requests',
    withdrawRequest: (id: string) => `/manage/plan-requests/${id}/withdraw`,
    cancellation: (subscription: string) =>
        `/manage/subscriptions/${subscription}/cancellation`,
    billing: '/manage/billing',
    invoice: (id: string) => `/manage/billing/invoices/${id}`,
    invoicePayments: (id: string) => `/manage/billing/invoices/${id}/payments`,
} as const;
