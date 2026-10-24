<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-danger']) }}>
    {{ $slot }}
</button>
