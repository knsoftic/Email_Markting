<button {{ $attributes->merge(['type' => 'submit', 'class' => 'kn-btn-danger']) }}>
    {{ $slot }}
</button>
