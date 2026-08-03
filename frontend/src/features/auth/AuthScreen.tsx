import { useState, type FormEvent } from 'react';
import { Button, ErrorNotice, Field, Panel } from '@/components/ui';
import { t } from '@/lib/i18n';
import { useLogin, useRegister } from './api';

export function AuthScreen() {
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const login = useLogin();
  const register = useRegister();

  const busy = login.isPending || register.isPending;
  const error = login.error ?? register.error;

  async function onSubmit(event: FormEvent) {
    event.preventDefault();

    if (mode === 'register') {
      await register.mutateAsync({ email, password });
    }

    // Registration signs the player straight in: making someone type the same
    // credentials twice in a row is friction with no security benefit.
    await login.mutateAsync({ email, password });
  }

  return (
    <main className="mx-auto flex min-h-dvh max-w-md flex-col justify-center gap-6 p-4">
      <header className="text-center">
        <h1 className="text-3xl font-semibold text-ember-400">{t('app.title')}</h1>
        <p className="mt-1 text-sm text-ash-400">{t('app.tagline')}</p>
      </header>

      <Panel>
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Field
            id="email"
            label={t('auth.email')}
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
          <Field
            id="password"
            label={t('auth.password')}
            type="password"
            autoComplete={mode === 'register' ? 'new-password' : 'current-password'}
            required
            minLength={10}
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />

          <ErrorNotice error={error} />

          <Button type="submit" variant="primary" busy={busy}>
            {busy ? t('auth.working') : t(mode === 'login' ? 'auth.signIn' : 'auth.register')}
          </Button>

          <Button
            variant="ghost"
            onClick={() => {
              setMode(mode === 'login' ? 'register' : 'login');
              login.reset();
              register.reset();
            }}
          >
            {t(mode === 'login' ? 'auth.needAccount' : 'auth.haveAccount')}
          </Button>
        </form>
      </Panel>
    </main>
  );
}
