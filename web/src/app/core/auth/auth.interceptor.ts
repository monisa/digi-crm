import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { API_BASE } from '../api.config';
import { AuthService } from './auth.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const token = auth.token();

  const isApi = req.url.startsWith(API_BASE);
  const authed = isApi && token ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : req;
  const request = isApi ? authed.clone({ setHeaders: { Accept: 'application/json' } }) : authed;

  return next(request).pipe(
    catchError((err: HttpErrorResponse) => {
      if (isApi && err.status === 401 && token) {
        auth.clearSession();
        router.navigate(['/login'], { queryParams: { expired: 1 } });
      } else if (isApi && err.status === 403 && err.error?.code === 'ONBOARDING_REQUIRED') {
        router.navigateByUrl('/onboarding');
      }
      return throwError(() => err);
    }),
  );
};
