import { Component, input, signal } from '@angular/core';
import { ReactiveFormsModule, FormControl } from '@angular/forms';

/** Outlined password input with the show/hide eye button from the Figma. */
@Component({
  selector: 'app-password-field',
  imports: [ReactiveFormsModule],
  template: `
    <div class="field" [class.invalid]="!!error()">
      <label [attr.for]="inputId()">{{ label() }} <span class="req">*</span></label>
      <input [id]="inputId()" [type]="show() ? 'text' : 'password'" [formControl]="control()"
             [attr.autocomplete]="autocomplete()" />
      <button type="button" class="eye" (click)="show.update((v) => !v)"
              [attr.aria-label]="show() ? 'Hide password' : 'Show password'">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
          <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" />
          @if (!show()) { <path d="M4 20 20 4" /> }
        </svg>
      </button>
      @if (error()) { <div class="err">{{ error() }}</div> }
    </div>
  `,
})
export class PasswordField {
  control = input.required<FormControl<string>>();
  inputId = input('password');
  label = input('Password');
  autocomplete = input('current-password');
  error = input<string | null | undefined>(null);
  protected readonly show = signal(false);
}
