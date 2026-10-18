<?php

namespace App\Modules\Administration\Enums;

/**
 * What platform staff may do on admin.<root>. Kept apart from organization
 * permissions: no platform permission reads customer data by itself; that
 * always needs an active support access grant.
 */
enum PlatformPermission: string
{
    case StaffManage = 'staff.manage';
    case OrganizationsView = 'organizations.view';
    case OrganizationsSuspend = 'organizations.suspend';
    case SupportRequest = 'support.request';
    case SupportApprove = 'support.approve';
    case RegistryManage = 'registry.manage';
    case PlansManage = 'plans.manage';
    case EntitlementsManage = 'entitlements.manage';
    case SecurityView = 'security.view';
    case LeadsManage = 'leads.manage';
    case BillingManage = 'billing.manage';
}
