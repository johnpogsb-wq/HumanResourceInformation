import { useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import EmployeeForm from '@/Pages/HR/Employees/Partials/EmployeeForm';
import { Button } from '@/Components/ui';

const BLANK_EMPLOYEE = {
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    birth_date: '',
    birth_place: '',
    gender: '',
    civil_status: '',
    nationality: 'Filipino',
    religion: '',
    blood_type: '',
    photo: null,

    email: '',
    mobile_number: '',
    phone_number: '',
    present_address: '',
    permanent_address: '',

    emergency_contact_name: '',
    emergency_contact_relationship: '',
    emergency_contact_number: '',

    sss_number: '',
    philhealth_number: '',
    pagibig_number: '',
    tin: '',

    department_id: '',
    position_id: '',
    supervisor_id: '',
    employment_status: 'probationary',
    employment_type: 'full_time',
    date_hired: '',
    date_regularized: '',
    date_separated: '',
    separation_reason: '',

    basic_salary: '',
    pay_frequency: 'semi_monthly',
    bank_name: '',
    bank_account_number: '',

    drivers_license_number: '',
    license_restriction_codes: '',
    license_expiry: '',

    status: 'active',
    notes: '',

    create_user_account: false,
    user_role: 'employee',
};

export default function Create({ options, nextEmployeeNumber }) {
    const { data, setData, post, processing, errors } = useForm(BLANK_EMPLOYEE);

    const submit = (event) => {
        event.preventDefault();
        post('/hr/employees', { forceFormData: true });
    };

    return (
        <AppLayout
            title="Add Employee"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Add Employee' },
            ]}
        >
            <form onSubmit={submit}>
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-sm text-muted-foreground">
                            Employee number{' '}
                            <span className="font-medium text-foreground">
                                {nextEmployeeNumber}
                            </span>{' '}
                            will be assigned on save.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button variant="outline" href="/hr/employees">
                            <ArrowLeft className="h-4 w-4" />
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            <Save className="h-4 w-4" />
                            Save Employee
                        </Button>
                    </div>
                </div>

                <EmployeeForm data={data} setData={setData} errors={errors} options={options} />

                <div className="mt-5 flex justify-end gap-2">
                    <Button variant="outline" href="/hr/employees">
                        Cancel
                    </Button>
                    <Button type="submit" loading={processing}>
                        <Save className="h-4 w-4" />
                        Save Employee
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
