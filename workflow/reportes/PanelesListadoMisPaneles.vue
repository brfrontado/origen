<template>
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Paneles disponibles</strong>
                </CCardHeader>
                <CCardBody>
                    <div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Buscar por</label>
                            <div class="row">
                                <div class="col-3">
                                    <select class="form-select" v-model="typeSearch">
                                        <option value="nombre">Nombre</option>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <input type="text" v-model="searchValue" class="form-control" placeholder="Escribe aquí tu búsqueda">
                                </div>
                            </div>
                        </div>
                    </div>
                    <EasyDataTable :headers="headers" :items="items" :search-field="typeSearch" :search-value="searchValue" alternating >
                        <template #item-operation="item">
                            <div>
                                <span @click="viewPanel(item)" class="cursor-pointer">
                                    <i class="fas fa-eye icon me-2"></i> Ver panel
                                </span>
                            </div>
                        </template>
                    </EasyDataTable>

                </CCardBody>
            </CCard>
        </CCol>
    </CRow>
</template>

<script>
import toolbox from "@/toolbox";

export default {
    name: 'Tables',
    data() {
        return {
            typeSearch: 'nombre',
            searchValue: '',
            headers: [
                {text: "Nombre de reporte", value: "nombre"},
                /*{text: "Activo", value: "activo"},*/
                {text: "Operación", value: "operation", width: '150'},
            ],
            items: []
        };
    },
    mounted() {
        this.getItems();
    },
    methods: {
        getItems() {

            const self = this;
            toolbox.doAjax('GET', 'paneles/mis-paneles', {},
                function (response) {
                    //self.items = response.data;
                    self.items = toolbox.prepareForTable(response.data);
                    //console.log(self.items);
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        viewPanel(item) {
            this.$router.push('/paneles/view/' + item.id);
        }
    }
}
</script>
