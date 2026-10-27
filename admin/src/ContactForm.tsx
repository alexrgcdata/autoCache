import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import type { AdminApi, ContactDetails } from "./types";

export function ContactForm({
  api,
  sample,
  kind,
  onBusy,
}: {
  api: AdminApi;
  sample: boolean;
  kind: "project" | "support";
  onBusy: (busy: boolean) => void;
}) {
  const [token, setToken] = useState("");
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");
  const [accepted, setAccepted] = useState(false);
  const [details, setDetails] = useState<ContactDetails>({
    name: "",
    email: "",
    phone: "",
    kind,
    message: "",
    website: "",
  });
  async function prepare() {
    setLoading(true);
    setError("");
    try {
      setToken((await api.contactSession()).csrf_token);
    } catch {
      setError(
        "The contact form is unavailable. Please use the email or phone link below.",
      );
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    void prepare();
  }, [api]);
  async function submit(event: FormEvent) {
    event.preventDefault();
    setSending(true);
    onBusy(true);
    setError("");
    try {
      const result = await api.contact(details, token);
      if (!result.accepted)
        throw new Error(
          "The message was not accepted. Please try email or phone.",
        );
      setAccepted(true);
      setDetails({
        name: "",
        email: "",
        phone: "",
        kind,
        message: "",
        website: "",
      });
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "Could not submit your message.",
      );
      // A successful-but-interrupted request can consume the old token.
      // Keep their details, but require an explicit retry before another submission.
      setToken("");
    } finally {
      setSending(false);
      onBusy(false);
    }
  }
  const change = (key: keyof ContactDetails, value: string) =>
    setDetails((current) => ({ ...current, [key]: value }));
  return accepted ? (
    <div className="contact-success" role="status">
      <span className="contact-check">✓</span>
      <h3>
        {sample ? "Sample message complete." : "Thanks for getting in touch."}
      </h3>
      <p>
        {sample
          ? "This is a local preview. No email was sent and your details were not stored."
          : "Your message was accepted for sending to RGC Data LLC. We’ll follow up using the contact details you supplied."}
      </p>
    </div>
  ) : (
    <form onSubmit={submit} className="contact-form">
      <p>
        Tell us a little about what you’re building or where you need a hand.
        We’ll get back to you by email or phone.
      </p>
      {sample && (
        <div className="contact-preview-note">
          Preview only · use fictional details · no email will be sent
        </div>
      )}
      {error && (
        <div className="alert error" role="alert">
          {error}
        </div>
      )}
      <fieldset disabled={sending}>
        <label>
          I’d like to discuss
          <select
            value={details.kind}
            onChange={(event) => change("kind", event.target.value)}
          >
            <option value="project">A project idea</option>
            <option value="support">Support for my setup</option>
          </select>
        </label>
        <div className="contact-columns">
          <label>
            Your name
            <input
              required
              autoComplete="name"
              value={details.name}
              maxLength={120}
              onChange={(event) => change("name", event.target.value)}
            />
          </label>
          <label>
            Email address
            <input
              type="email"
              required
              autoComplete="email"
              value={details.email}
              maxLength={254}
              onChange={(event) => change("email", event.target.value)}
            />
          </label>
        </div>
        <label>
          Phone <small>(optional)</small>
          <input
            type="tel"
            autoComplete="tel"
            value={details.phone}
            maxLength={60}
            onChange={(event) => change("phone", event.target.value)}
          />
        </label>
        <label>
          {details.kind === "project"
            ? "What do you have in mind?"
            : "What would you like help with?"}
          <textarea
            required
            minLength={10}
            maxLength={6000}
            rows={5}
            value={details.message}
            onChange={(event) => change("message", event.target.value)}
            placeholder="A short overview, what you want to achieve, and any useful timing or context…"
          />
        </label>
        <label className="contact-honeypot" aria-hidden="true">
          Leave this field empty
          <input
            tabIndex={-1}
            autoComplete="off"
            value={details.website}
            onChange={(event) => change("website", event.target.value)}
          />
        </label>
        <p className="contact-fineprint">
          Your details will be emailed to RGC Data LLC to respond to this
          inquiry. Please leave out passwords and private record data.
          {details.kind === "support" &&
            " Support work is scoped and quoted before we begin."}
        </p>
        <button
          className="button yellow full"
          disabled={loading || !token || sending}
        >
          {sending
            ? "Sending…"
            : loading
              ? "Preparing form…"
              : sample
                ? "Try sample submission →"
                : "Send inquiry →"}
        </button>
      </fieldset>
      {!token && !loading && (
        <button
          type="button"
          className="text-button"
          onClick={() => void prepare()}
        >
          Prepare form again
        </button>
      )}
      <p className="contact-direct">
        Prefer to reach out directly?{" "}
        <a href="mailto:ron@rgcdata.com">ron@rgcdata.com</a> ·{" "}
        <a href="tel:+12707705871">270-770-5871</a>
      </p>
    </form>
  );
}
