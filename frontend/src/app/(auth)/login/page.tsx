"use client";

import Link from "next/link";
import { useState } from "react";
import { Button, Card, ErrorNote, Input } from "@/components/ui";
import { useLogin } from "@/hooks/useAuth";
import { ApiError } from "@/lib/api";

export default function LoginPage() {
  const login = useLogin();
  const [form, setForm] = useState({ email: "", password: "" });
  const error = login.error instanceof ApiError ? login.error : null;

  return (
    <Card className="p-6">
      <h1 className="mb-4 text-base font-semibold">Вход</h1>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault();
          login.mutate(form);
        }}
      >
        <Input label="Почта" type="email" autoComplete="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} error={error?.field("email")} />
        <Input label="Пароль" type="password" autoComplete="current-password" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
        {error && !error.field("email") && <ErrorNote error={error} />}
        <Button type="submit" className="w-full" disabled={login.isPending}>
          {login.isPending ? "Входим…" : "Войти"}
        </Button>
      </form>
      <p className="mt-4 text-center text-xs text-muted">
        Нет аккаунта?{" "}
        <Link href="/register" className="text-accent hover:underline">
          Зарегистрироваться
        </Link>
      </p>
    </Card>
  );
}
