"use client";

import Link from "next/link";
import { useState } from "react";
import { Button, Card, ErrorNote, Input } from "@/components/ui";
import { useRegister } from "@/hooks/useAuth";
import { ApiError } from "@/lib/api";

export default function RegisterPage() {
  const register = useRegister();
  const [form, setForm] = useState({ name: "", email: "", password: "", password_confirmation: "", organization: "" });
  const error = register.error instanceof ApiError ? register.error : null;
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: e.target.value });

  return (
    <Card className="p-6">
      <h1 className="mb-4 text-base font-semibold">Регистрация</h1>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault();
          register.mutate({ ...form, organization: form.organization || undefined });
        }}
      >
        <Input label="Имя" required value={form.name} onChange={set("name")} error={error?.field("name")} />
        <Input label="Почта" type="email" autoComplete="email" required value={form.email} onChange={set("email")} error={error?.field("email")} />
        <Input label="Пароль" type="password" autoComplete="new-password" required minLength={8} value={form.password} onChange={set("password")} error={error?.field("password")} />
        <Input label="Пароль ещё раз" type="password" autoComplete="new-password" required value={form.password_confirmation} onChange={set("password_confirmation")} />
        <Input label="Организация" placeholder="Название компании" value={form.organization} onChange={set("organization")} error={error?.field("organization")} hint="Можно создать позже" />
        {error && !Object.keys(error.errors).length && <ErrorNote error={error} />}
        <Button type="submit" className="w-full" disabled={register.isPending}>
          {register.isPending ? "Создаём…" : "Создать аккаунт"}
        </Button>
      </form>
      <p className="mt-4 text-center text-xs text-muted">
        Уже есть аккаунт?{" "}
        <Link href="/login" className="text-accent hover:underline">
          Войти
        </Link>
      </p>
    </Card>
  );
}
