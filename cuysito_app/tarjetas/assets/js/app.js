"use strict";

const TarjetasApp = (() => {
    const { hydrateIcons, toast, closeOverlay } = TarjetasUI;
    const state = {
        emitters: [],
        profiles: [],
        cards: [],
        preferences: {
            selected_preset: "split",
            custom_presets: [],
            theme: "system",
        },
        currentPreset: null,
        selectedProfileId: null,
    };
    let loadSequence = 0;
    let pinLength = 5;

    function setTheme(theme) {
        const root = document.documentElement;
        if (theme === "system") root.removeAttribute("data-theme");
        else root.dataset.theme = theme;
        const button = document.getElementById("tc-theme");
        if (!button) return;
        const effective = theme === "system"
            ? (matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark")
            : theme;
        button.replaceChildren(TarjetasUI.icon(effective === "light" ? "moon" : "sun"));
        button.title = effective === "light" ? "Usar tema oscuro" : "Usar tema claro";
    }

    function normalizeSnapshot(payload) {
        state.emitters = (payload.emitters || []).map((emitter) => ({
            ...emitter,
            id: Number(emitter.id),
            sort_order: Number(emitter.sort_order),
        }));
        state.profiles = (payload.profiles || []).map((profile) => ({
            ...profile,
            id: Number(profile.id),
            emitter_id: Number(profile.emitter_id),
            sort_order: Number(profile.sort_order),
            card_count: Number(profile.card_count),
        }));
        state.cards = (payload.cards || []).map((card) => ({
            ...card,
            id: Number(card.id),
            profile_id: Number(card.profile_id),
            uses_remaining: Number(card.uses_remaining),
            sort_order: Number(card.sort_order),
        }));
        state.preferences = {
            selected_preset: payload.preferences?.selected_preset || "split",
            custom_presets: Array.isArray(payload.preferences?.custom_presets) ? payload.preferences.custom_presets : [],
            theme: payload.preferences?.theme || "system",
        };
        if (state.selectedProfileId && !state.profiles.some((profile) => profile.id === state.selectedProfileId)) {
            state.selectedProfileId = null;
        }
    }

    async function reload() {
        const sequence = ++loadSequence;
        try {
            const payload = await TarjetasAPI.data.snapshot();
            if (sequence !== loadSequence) return;
            normalizeSnapshot(payload);
            render();
        } catch (error) {
            handleError(error);
            throw error;
        }
    }

    function renderProfileFilter() {
        const filter = document.getElementById("tc-profile-filter");
        const selected = String(state.selectedProfileId || filter.value || "");
        filter.replaceChildren(TarjetasUI.el("option", { value: "", text: "Todos los perfiles" }));
        state.profiles.forEach((profile) => {
            filter.appendChild(TarjetasUI.el("option", {
                value: profile.id,
                text: profile.holder,
            }));
        });
        if ([...filter.options].some((option) => option.value === selected)) filter.value = selected;
        else filter.value = "";
    }

    function renderStats() {
        document.getElementById("tc-stat-active").textContent = state.cards.length;
        document.getElementById("tc-stat-ready").textContent = state.cards
            .filter((card) => TarjetasUI.cooldown(card.cooldown_until).ready).length;
        document.getElementById("tc-stat-profiles").textContent = state.profiles.length;
    }

    function render() {
        renderStats();
        renderProfileFilter();
        TarjetasPresets.renderSelect();
        setTheme(state.preferences.theme);
        TarjetasPresets.apply(state.preferences.selected_preset);
        TarjetasProfiles.render();
        document.getElementById("tc-app").classList.add("is-ready");
        document.getElementById("tc-app").setAttribute("aria-hidden", "false");
    }

    function selectProfile(id) {
        state.selectedProfileId = id ? Number(id) : null;
        const filter = document.getElementById("tc-profile-filter");
        filter.value = state.selectedProfileId ? String(state.selectedProfileId) : "";
        TarjetasProfiles.render();
        TarjetasCards.render();
    }

    async function savePreferences() {
        try {
            const response = await TarjetasAPI.preferences.save(state.preferences);
            state.preferences = response.preferences;
        } catch (error) {
            handleError(error);
        }
    }

    function relock() {
        document.getElementById("tc-app").classList.remove("is-ready");
        document.getElementById("tc-app").setAttribute("aria-hidden", "true");
        closeOverlay();
        TarjetasLock.show({
            pinLength,
            onSuccess: async () => {
                await reload();
            },
        });
    }

    function handleError(error) {
        if (error instanceof TarjetasAPI.ApiError && [401, 419].includes(error.status)) {
            relock();
            return;
        }
        toast(error?.message || "Ocurrió un error inesperado.", "error");
    }

    function openSettings(tab = "emitters") {
        TarjetasPresets.openManager(tab);
    }

    function wireEvents() {
        document.getElementById("tc-add-card").addEventListener("click", () =>
            TarjetasCards.openForm(state.selectedProfileId));
        document.getElementById("tc-add-profile").addEventListener("click", () =>
            TarjetasProfiles.openForm());
        document.getElementById("tc-add-profile-header").addEventListener("click", () =>
            TarjetasProfiles.openForm());
        document.getElementById("tc-settings").addEventListener("click", () => openSettings());
        document.getElementById("tc-edit-layout").addEventListener("click", () =>
            TarjetasPresets.openBuilder());
        document.getElementById("tc-theme").addEventListener("click", async () => {
            const effective = document.documentElement.dataset.theme
                || (matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark");
            state.preferences.theme = effective === "light" ? "dark" : "light";
            setTheme(state.preferences.theme);
            await savePreferences();
        });
        document.getElementById("tc-logout").addEventListener("click", async () => {
            try {
                await TarjetasAPI.auth.logout();
            } catch (error) {
                if (!(error instanceof TarjetasAPI.ApiError && error.status === 401)) handleError(error);
            }
            relock();
        });
        document.getElementById("tc-preset-select").addEventListener("change", (event) =>
            TarjetasPresets.apply(event.target.value, true));
        document.getElementById("tc-card-search").addEventListener("input", () => TarjetasCards.render());
        document.getElementById("tc-ready-only").addEventListener("change", () => TarjetasCards.render());
        document.getElementById("tc-profile-filter").addEventListener("change", (event) => {
            state.selectedProfileId = Number(event.target.value || 0) || null;
            TarjetasProfiles.render();
            TarjetasCards.render();
        });
        document.getElementById("tc-profile-search").addEventListener("input", () => TarjetasProfiles.render());
        document.addEventListener("keydown", (event) => {
            if (event.ctrlKey || event.metaKey || event.altKey) return;
            if (["INPUT", "SELECT", "TEXTAREA"].includes(document.activeElement?.tagName)) return;
            if (event.key.toLowerCase() === "n") TarjetasCards.openForm(state.selectedProfileId);
            if (event.key.toLowerCase() === "p") TarjetasProfiles.openForm();
        });
    }

    async function start() {
        hydrateIcons();
        TarjetasProfiles.init({
            state,
            reload,
            selectProfile,
            handleError,
            openCardForm: (profileId) => TarjetasCards.openForm(profileId),
            openSettings,
        });
        TarjetasCards.init({
            state,
            reload,
            selectProfile,
            handleError,
            openProfileForm: () => TarjetasProfiles.openForm(),
        });
        TarjetasPresets.init({
            state,
            reload,
            handleError,
            renderCards: () => TarjetasCards.render(),
            savePreferences,
            setTheme,
        });
        wireEvents();

        try {
            const auth = await TarjetasAPI.auth.status();
            pinLength = auth.pin_length;
            if (auth.authenticated) {
                TarjetasAPI.setCsrf(auth.csrf_token);
                await reload();
                return;
            }
            TarjetasLock.show({
                pinLength: auth.pin_length,
                lockedFor: auth.locked_for,
                onSuccess: async () => reload(),
            });
        } catch (error) {
            handleError(error);
            TarjetasLock.show({ pinLength: 5, onSuccess: async () => reload() });
        }
    }

    document.addEventListener("DOMContentLoaded", start);
    return Object.freeze({ state, reload });
})();
