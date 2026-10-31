import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

/** Signed in, account active (sends new clients to onboarding). */
export const authGuard: CanActivateFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const profile = await auth.ensureProfile();
  if (!profile) return router.parseUrl('/login');
  if (profile.user.needs_onboarding) return router.parseUrl('/onboarding');
  return true;
};

/** Login/register pages: skip them when already signed in. */
export const guestGuard: CanActivateFn = async () => {
  const auth = inject(AuthService);
  const profile = await auth.ensureProfile();
  return profile ? inject(Router).parseUrl(auth.homeUrl()) : true;
};

/** Onboarding page: only for users who still need it. */
export const onboardingGuard: CanActivateFn = async () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const profile = await auth.ensureProfile();
  if (!profile) return router.parseUrl('/login');
  return profile.user.needs_onboarding ? true : router.parseUrl('/dashboard');
};

/** Module access set by the admin, e.g. data: { module: 'leads' }. */
export const moduleGuard: CanActivateFn = (route) => {
  const auth = inject(AuthService);
  const module = route.data['module'] as string | undefined;
  return !module || auth.canView(module) ? true : inject(Router).parseUrl('/dashboard');
};
